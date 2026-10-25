import java.io.File;
import java.io.FileInputStream;
import java.io.InputStream;
import java.io.PrintWriter;
import java.security.MessageDigest;
import java.time.Instant;
import java.util.ArrayList;
import java.util.List;

import weka.classifiers.Classifier;
import weka.core.Attribute;
import weka.core.DenseInstance;
import weka.core.Instance;
import weka.core.Instances;
import weka.core.OptionHandler;
import weka.core.SerializationHelper;
import weka.core.Utils;
import weka.core.Version;
import weka.core.converters.ConverterUtils.DataSource;

/**
 * Loads a J48 model saved by Weka and exports what Weka itself predicts for every possible
 * ProfilePath input, so the PHP application can serve the model without running Java.
 *
 * The model's inputs are six numeric 0/1 attributes R, I, A, S, E, C with exactly three 1s
 * (the student's top three RIASEC types), so there are only 20 possible inputs. For each one
 * this tool builds a Weka instance with the class set to missing, and records Weka's own
 * classifyInstance() result and its full distributionForInstance(). Nothing is recomputed
 * outside Weka.
 *
 *   java -cp "weka.jar:export-j48.jar" ExportJ48Lookup <model> <out.json> <version> --encoding top3-binary
 *        [--header train.arff] [--training-records N] [--training-data "name/version"]
 *        [--evaluation "protocol"]
 *   (Windows: use ; instead of : in -cp)
 *
 * --encoding must be declared and must be top3-binary: the only encoding this lookup can serve
 * (1 = one of the student's three highest RIASEC scores, 0 = not). A raw-score model has the same
 * header (six numeric attributes), so the tree is also checked: every split must lie in [0, 1),
 * as binary inputs give; a split such as "C <= 12" stops the export.
 * --header is needed only when the .model file has no training header (models saved from the
 * Explorer include it). The training and evaluation options record what the team declares; the
 * .model file itself does not contain them, and anything not given is exported as unknown (null).
 */
public class ExportJ48Lookup {
    static final String[] DIMS = {"R", "I", "A", "S", "E", "C"};

    public static void main(String[] args) throws Exception {
        List<String> positional = new ArrayList<>();
        java.util.Map<String, String> opt = new java.util.HashMap<>();
        for (int i = 0; i < args.length; i++) {
            if (args[i].startsWith("--")) {
                if (i + 1 >= args.length) {
                    fail("Missing value for " + args[i]);
                }
                opt.put(args[i], args[++i]);
            } else {
                positional.add(args[i]);
            }
        }
        if (positional.size() < 3) {
            System.err.println("Usage: ExportJ48Lookup <model> <out.json> <version> --encoding top3-binary [--header train.arff]"
                + " [--training-records N] [--training-data name] [--evaluation protocol]");
            System.exit(2);
        }
        if (positional.size() > 3) {
            opt.putIfAbsent("--header", positional.get(3));   // older form: header as 4th argument
        }
        String encoding = opt.get("--encoding");
        if (encoding == null) {
            fail("Declare the feature encoding the model was trained on: --encoding top3-binary");
        }
        if (!encoding.equals("top3-binary")) {
            fail("This export serves only --encoding top3-binary (found '" + encoding + "'). A model trained on raw scores cannot be served by the 20-input lookup.");
        }
        args = positional.toArray(new String[0]);
        File modelFile = new File(args[0]);
        Object[] objects = SerializationHelper.readAll(modelFile.getPath());
        if (objects.length == 0 || !(objects[0] instanceof Classifier)) {
            fail("The file does not contain a Weka classifier.");
        }
        Classifier classifier = (Classifier) objects[0];
        Instances header = null;
        if (objects.length > 1 && objects[1] instanceof Instances) {
            header = new Instances((Instances) objects[1], 0);
        } else if (opt.containsKey("--header")) {
            header = new Instances(new DataSource(opt.get("--header")).getDataSet(), 0);
        } else {
            fail("The model has no training header. Pass the training .arff with --header.");
        }
        if (header.classIndex() < 0) {
            header.setClassIndex(header.numAttributes() - 1);
        }

        // ---- header checks: these stop the export instead of guessing ----
        if (!classifier.getClass().getName().equals("weka.classifiers.trees.J48")) {
            fail("Expected weka.classifiers.trees.J48, found " + classifier.getClass().getName());
        }
        if (header.numAttributes() != 7) {
            fail("Expected 7 attributes (R, I, A, S, E, C, class), found " + header.numAttributes());
        }
        for (int i = 0; i < 6; i++) {
            Attribute a = header.attribute(i);
            if (!a.name().trim().equalsIgnoreCase(DIMS[i])) {
                fail("Attribute " + (i + 1) + " is '" + a.name() + "', expected '" + DIMS[i] + "' (order must be R, I, A, S, E, C)");
            }
            if (!a.isNumeric()) {
                fail("Attribute " + DIMS[i] + " must be numeric (0/1); it is " + Attribute.typeToString(a));
            }
        }
        if (header.classIndex() != 6 || !header.classAttribute().isNominal()) {
            fail("The class must be the 7th attribute and nominal (index " + header.classIndex() + ")");
        }

        // ---- the tree must fit 0/1 inputs: every numeric split in [0, 1) ----
        java.util.regex.Pattern split = java.util.regex.Pattern.compile("^[|\\s]*([A-Za-z]+)\\s*(<=|>=|<|>)\\s*(-?[0-9.]+(?:[Ee][-+]?[0-9]+)?)");
        for (String line : classifier.toString().split("\\r?\\n")) {
            java.util.regex.Matcher m = split.matcher(line);
            if (m.find()) {
                double t = Double.parseDouble(m.group(3));
                if (t < 0 || t >= 1) {
                    fail("The tree splits at '" + line.trim() + "'. Top-three binary inputs are 0 or 1, so every split must be in [0, 1). "
                        + "This looks like a model trained on raw scores; it cannot be served by the 20-input lookup.");
                }
            }
        }

        Attribute cls = header.classAttribute();
        List<String> labels = new ArrayList<>();
        for (int k = 0; k < cls.numValues(); k++) {
            labels.add(cls.value(k));
        }

        StringBuilder profiles = new StringBuilder();
        int n = 0;
        for (int a = 0; a < 6; a++) {
            for (int b = a + 1; b < 6; b++) {
                for (int c = b + 1; c < 6; c++) {
                    double[] bits = new double[6];
                    bits[a] = 1; bits[b] = 1; bits[c] = 1;
                    Instance inst = new DenseInstance(7);
                    inst.setDataset(header);
                    for (int i = 0; i < 6; i++) {
                        inst.setValue(i, bits[i]);
                    }
                    inst.setClassMissing();  // the preferred course is never an input
                    double predicted = classifier.classifyInstance(inst);
                    double[] dist = classifier.distributionForInstance(inst);
                    if (dist.length != labels.size()) {
                        fail("Distribution has " + dist.length + " values for " + labels.size() + " classes");
                    }
                    StringBuilder key = new StringBuilder();
                    StringBuilder d = new StringBuilder();
                    for (int i = 0; i < 6; i++) {
                        key.append(i > 0 ? "," : "").append((int) bits[i]);
                    }
                    for (int k = 0; k < dist.length; k++) {
                        d.append(k > 0 ? ", " : "").append(Double.toString(dist[k]));
                    }
                    profiles.append(n > 0 ? ",\n" : "\n")
                        .append("    \"").append(key).append("\": {\"predicted_index\": ")
                        .append(Utils.isMissingValue(predicted) ? "null" : String.valueOf((int) predicted))
                        .append(", \"predicted\": ")
                        .append(Utils.isMissingValue(predicted) ? "null" : json(labels.get((int) predicted)))
                        .append(", \"distribution\": [").append(d).append("]}");
                    n++;
                }
            }
        }

        String options = classifier instanceof OptionHandler ? Utils.joinOptions(((OptionHandler) classifier).getOptions()) : "";
        StringBuilder labelJson = new StringBuilder();
        for (int k = 0; k < labels.size(); k++) {
            labelJson.append(k > 0 ? ", " : "").append(json(labels.get(k)));
        }
        StringBuilder attrJson = new StringBuilder();
        for (int i = 0; i < 6; i++) {
            attrJson.append(i > 0 ? ", " : "").append(json(header.attribute(i).name()));
        }

        try (PrintWriter out = new PrintWriter(args[1], "UTF-8")) {
            out.println("{");
            out.println("  \"format\": \"weka-j48-binary-lookup\",");
            out.println("  \"version\": " + json(args[2]) + ",");
            out.println("  \"source_model\": " + json(modelFile.getName()) + ",");
            out.println("  \"model_sha256\": " + json(sha256(modelFile)) + ",");
            out.println("  \"weka_version\": " + json(Version.VERSION) + ",");
            out.println("  \"exported_at\": " + json(Instant.now().toString()) + ",");
            out.println("  \"classifier\": " + json(classifier.getClass().getName()) + ",");
            out.println("  \"options\": " + json(options) + ",");
            out.println("  \"relation\": " + json(header.relationName()) + ",");
            out.println("  \"attributes\": [" + attrJson + "],");
            out.println("  \"attribute_type\": \"numeric\",");
            out.println("  \"feature_encoding\": \"top3-binary\",");
            out.println("  \"feature_encoding_description\": \"1 = one of the student's three highest RIASEC scores, 0 = not; equal scores taken in R, I, A, S, E, C order (project rule); exactly three 1s\",");
            out.println("  \"target_definition\": \"The student's stated intended/preferred course (dataset column '" + jsonInner(header.classAttribute().name())
                + "'); not verified enrolment and not an independently assessed suitable course\",");
            out.println("  \"training\": {\"records\": " + (opt.containsKey("--training-records") ? String.valueOf(Integer.parseInt(opt.get("--training-records"))) : "null")
                + ", \"data_version\": " + (opt.containsKey("--training-data") ? json(opt.get("--training-data")) : "null")
                + ", \"source\": \"declared at export; not stored in the .model file\"},");
            out.println("  \"evaluation\": {\"protocol\": " + (opt.containsKey("--evaluation") ? json(opt.get("--evaluation")) : "null")
                + ", \"source\": \"declared at export; not stored in the .model file\"},");
            out.println("  \"exporter_version\": 2,");
            out.println("  \"class_attribute\": " + json(cls.name()) + ",");
            out.println("  \"class_index\": " + header.classIndex() + ",");
            out.println("  \"class_labels\": [" + labelJson + "],");
            out.println("  \"tree\": " + json(classifier.toString()) + ",");
            out.print("  \"profiles\": {" + profiles + "\n  }\n}\n");
        }
        System.out.println("Weka " + Version.VERSION + ": " + classifier.getClass().getName() + " " + options);
        System.out.println("Header: " + String.join(", ", DIMS) + " (numeric) -> " + cls.name() + " (" + labels.size() + " classes, index " + header.classIndex() + ")");
        System.out.println("Exported Weka's prediction and distribution for all " + n + " profiles to " + args[1]);
    }

    static void fail(String message) {
        System.err.println("STOPPED: " + message);
        System.exit(1);
    }

    static String sha256(File f) throws Exception {
        MessageDigest md = MessageDigest.getInstance("SHA-256");
        try (InputStream in = new FileInputStream(f)) {
            byte[] buf = new byte[8192];
            for (int r; (r = in.read(buf)) > 0; ) {
                md.update(buf, 0, r);
            }
        }
        StringBuilder hex = new StringBuilder();
        for (byte b : md.digest()) {
            hex.append(String.format("%02x", b));
        }
        return hex.toString();
    }

    static String jsonInner(String s) {
        String q = json(s);
        return q.substring(1, q.length() - 1);
    }

    static String json(String s) {
        StringBuilder b = new StringBuilder("\"");
        for (char ch : s.toCharArray()) {
            switch (ch) {
                case '"': b.append("\\\""); break;
                case '\\': b.append("\\\\"); break;
                case '\n': b.append("\\n"); break;
                case '\r': b.append("\\r"); break;
                case '\t': b.append("\\t"); break;
                default:
                    if (ch < 0x20) {
                        b.append(String.format("\\u%04x", (int) ch));
                    } else {
                        b.append(ch);
                    }
            }
        }
        return b.append('"').toString();
    }
}
