# Prediction Module: Weka J48 in ProfilePath

The Prediction Module shows guidance staff the course a Weka J48 model predicts for a student,
beside the content-based results (Best RIASEC Match, Alternative Courses) and the student's
Preferred Course. It never changes those results.

## What the model is

| | |
|---|---|
| File | `models/ProfilePath_J48_Binary_393.model` (SHA-256 `42076366…ee83754`) |
| Classifier | `weka.classifiers.trees.J48 -C 0.1 -M 10`, saved with Weka 3.9.6, training header included |
| Training data | 393 students (`ONET_RIASEC_Raw_393_Updated.csv`: the six O\*NET Mini Interest Profiler scores, 0–20 (the same 30 items as ProfilePath's assessment; stored score = 2 × O\*NET + 10, same ranking), and the stated course), converted with `python evaluation/cbf_eval.py arff --students <file>` |
| Inputs | `R, I, A, S, E, C`, numeric, 1 = one of the student's three highest RIASEC scores, 0 = not (ties for 3rd place in R, I, A, S, E, C order: 84 of 393 students) |
| Class | `Actual Course` (index 6), 28 labels: the students' stated intended/preferred courses |
| Tree | 7 leaves, size 13; predicts 7 different courses |

```
R <= 0
|   C <= 0
|   |   I <= 0: B Multimedia Arts (51.0/42.0)
|   |   I > 0
|   |   |   A <= 0: BS Nursing (33.0/18.0)
|   |   |   A > 0: BS Medical Technology (69.0/57.0)
|   C > 0: BS Computer Science (55.0/44.0)
R > 0
|   A <= 0: BS Civil Engineering (118.0/93.0)
|   A > 0
|   |   E <= 0: BS Mechanical Engineering (40.0/30.0)
|   |   E > 0: BS Architecture (27.0/22.0)
```

`(51.0/42.0)` means 51 training students reach the leaf and 42 of them chose another course, so
the leaf's probability for its course is 9/51. `config/course_aliases.php` maps the class labels
(e.g. `BSBA Major in Financial Management`) to the program list. Four programs are not among the 28
classes (BS Accounting Information System, BSBA Sustainability Management, BS International
Business, BS Business Analytics with AI): the model gives them no prediction and no probability.

## How it runs (no Java on the server)

The inputs always have exactly three 1s, so there are only **20 possible inputs**.
`ExportJ48Lookup` loads the `.model` file with Weka, checks its header, and records Weka's own
`classifyInstance()` and `distributionForInstance()` for each of the 20 inputs (class set to
missing) in `config/prediction_model.json`. PHP (`modules/ContentBasedFiltering/lib/PredictionModel.php`) then:

1. checks the six raw scores against the assessment's rules (whole numbers, 10–50); a missing or
   invalid score is rejected, never replaced by 0;
2. sets 1 for the three highest scores, 0 for the rest; equal scores are taken in R, I, A, S, E, C
   order (the rule used to prepare the training data; a project rule, not an O\*NET rule);
3. looks up Weka's prediction and probabilities for that input.

Because every possible input was answered by Weka itself, the application's predictions and
probabilities are Weka's. `db/verify_prediction_model.php` checks this against Weka's command line.

**Display rule for equal probabilities:** courses with equal probability are listed in the
model's class-label order (the order in the .model header) and marked "tied" on the staff card.
Weka's own predicted class is the first class with the highest probability, so the first course
shown is always Weka's prediction. Tie-averaged evaluation metrics (Hit@3, MRR) do not describe
this display order exactly.

**Probabilities** are the share of past students at the tree's leaf who intended each course.
They are not suitability scores and are not shown as percentages.

## Retraining (how the installed model was built)

1. **Training file.** Keep the data file outside the repository (or in `evaluation/`, which git
   ignores). It needs R, I, A, S, E, C scores and a course column (a student column is optional):
   ```
   python evaluation/cbf_eval.py arff --students ONET_RIASEC_Raw_393_Updated.csv --out all_binary.arff
   ```
   This sets 1 for each student's three highest scores (ties in R, I, A, S, E, C order, the same
   rule as the application) and declares only the courses present.
2. **Ceiling check.** `python evaluation/accuracy_ceiling.py --data ONET_RIASEC_Raw_393_Updated.csv`
3. **Evaluate** in the Weka Explorer: open `all_binary.arff`, Classify → `trees.J48`, set
   `confidenceFactor 0.1` and `minNumObj 10`, Cross-validation 10 folds, and repeat with the random
   seed (More options → Random seed) 1 to 10; do the same with `rules.ZeroR`. Record the mean and
   standard deviation of the accuracy and kappa. (Command line: `java -cp weka.jar
   weka.classifiers.trees.J48 -t all_binary.arff -x 10 -s 1 -C 0.1 -M 10`.)
4. **Final model** on all records: in the Explorer use "Use training set", Start, then right-click
   the result → Save model, as `models/ProfilePath_J48_Binary_<records>.model`. (Command line: add
   `-no-cv -d models/ProfilePath_J48_Binary_393.model`.) The training-set accuracy Weka prints here is
   not an evaluation result; report the cross-validation figures.
5. Re-export and check it as below, then update the record count, the evaluation text and the
   tests (`tests/prediction_module_test.php`, the fixtures in `tests/fixtures/`).

## Re-export after retraining

Run from the project folder. Use Weka's own `weka.jar` (same version that saved the model). In
Windows Command Prompt (paths for a Weka 3.9.6 install in `D:\Weka\Weka-3-9-6`):

```
set WEKA=D:\Weka\Weka-3-9-6\weka.jar

:: 1. Export Weka's predictions for all 20 inputs
java -cp "%WEKA%;tools\weka\export-j48.jar" ExportJ48Lookup models\ProfilePath_J48_Binary_393.model config\prediction_model.json "ProfilePath J48 Binary (J48 -C 0.1 -M 10, 393 records)" --encoding top3-binary --training-records 393 --training-data "ONET_RIASEC_Raw_393_Updated (top-3 binary)" --evaluation "10-fold cross-validation, 10 runs (seeds 1-10), Weka 3.9.6: accuracy 17.12% (sd 1.24), kappa 0.107, top-3 40.89%; ZeroR 7.76%"

:: 2. Check the application against Weka's own command line
php db\verify_prediction_model.php --write-arff profiles20.arff
java -cp "%WEKA%" weka.classifiers.trees.J48 -l models\ProfilePath_J48_Binary_393.model -T profiles20.arff -classifications "weka.classifiers.evaluation.output.prediction.CSV -distribution -decimals 16" > weka_predictions.csv
php db\verify_prediction_model.php weka_predictions.csv

:: 3. Recompute saved recommendations with the new model
php db\recompute_recommendations.php
```

`--encoding top3-binary` is required: it declares that the model was trained on the 0/1
top-three inputs. The export also refuses a tree whose splits do not fit 0/1 inputs (a model
trained on raw scores splits at values such as `C <= 12`), and the application refuses such a
file at run time (`PredictionModel::validateLookup`). `--training-records`, `--training-data` and
`--evaluation` record what the team declares; the .model file does not contain them, and anything
left out is shown to staff as "unknown". Raw-score models (e.g. exploratory experiments) cannot be
deployed through this lookup.

If `java` is not found, use the `java.exe` inside Weka's own folder (`dir /s /b D:\Weka\Weka-3-9-6\java.exe`).
On macOS/Linux use `:` instead of `;` in `-cp`. If the model was saved without its training
header, add `--header train.arff` to step 1.

`export-j48.jar` is built from `ExportJ48Lookup.java` for Java 8+:
`javac --release 8 -cp weka.jar ExportJ48Lookup.java && jar cf export-j48.jar ExportJ48Lookup.class`.

The export stops (and the old file is kept) if the classifier is not J48, the inputs are not
`R, I, A, S, E, C` in that order and numeric, or the class is not the 7th, nominal attribute.
The application refuses a damaged file (`PredictionModel::validateLookup`): the staff card then
says the Prediction Module is unavailable, and the content-based results are shown as usual.

## API (staff only)

`GET modules/ContentBasedFiltering/api/recommendations.php?studentId=<id>` with a staff session returns `predictionDetail`:

```json
{
  "status": "available",
  "inputs": {"R": 1, "I": 1, "A": 0, "S": 1, "E": 0, "C": 0},
  "predicted": {"label": "BS Civil Engineering", "title": "BS Civil Engineering", "programId": 23,
                "probability": 0.21739130434782608, "inBestRiasecMatch": false, "inAlternatives": false, "tied": false},
  "top3": ["... the three highest, ties in the model's class order"],
  "distribution": ["... all 28 classes in the model's class order"],
  "model": {"version": "ProfilePath J48 Binary (J48 -C 0.1 -M 10, 393 records)", "wekaVersion": "3.9.6",
            "classifier": "weka.classifiers.trees.J48 -C 0.1 -M 10", "classAttribute": "Actual Course", "classes": 28,
            "sha256": "42076366…"},
  "savedPredictionDiffers": false
}
```

When it cannot run: `{"status": "unavailable", "reason": "…"}`. Students never receive
`predictionDetail`. The model and configuration files are not served over the web (`router.php`).

## What the evaluation results mean

The class is the students' stated intended course, so accuracy measures agreement with stated
intentions, not whether a course suits a student.

Results on the 393 records, Weka 3.9.6, stratified 10-fold cross-validation repeated with seeds 1–10
(`weka.classifiers.trees.J48 -t all_binary.arff -x 10 -s <seed>`):

| Model | Accuracy, mean (sd) | Kappa | Course in top 3 | MRR |
|---|---|---|---|---|
| **J48 -C 0.1 -M 10 (deployed)** | **17.12% (1.24)**, range 14.50–18.83 | 0.107 | 40.89% | 0.343 |
| J48 -C 0.25 -M 2 (Weka default, comparison) | 17.20% (1.16) | 0.109 | 40.43% | 0.343 |
| ZeroR (always the largest class) | 7.76% (0.18) | −0.007 | 24.45% | 0.241 |

**Weka Experimenter** (same file; Cross-validation, 10 folds, 10 repetitions; Analyse with the
Paired T-Tester (corrected), Percent_correct, test base ZeroR, significance 0.05): ZeroR 7.76 (0.58),
J48 17.12 (4.45), marked "v" (significantly better than ZeroR; corrected resampled t = 6.04,
df 99). Kappa_statistic: ZeroR 0.00 (0.00), J48 0.11 (0.05) v. Both reproduced on the team's own
Weka 3.9.6 (2026-10-25). The Experimenter's standard deviation is over the 100 individual folds (about 39 students
each); the 1.24 above is over the 10 run means. Both describe the same runs: report which one you use.

Top 3 and MRR come from the cross-validation predictions, with tied probabilities sharing
positions. The settings were not tuned on these runs, so the figures are not inflated by model
selection.

**Upper limit of these inputs** (`python evaluation/accuracy_ceiling.py --data <file>`): the 20
input patterns each mix many courses (e.g. 51 students with A, S, E chose 17 different courses), so
no model using only these inputs can exceed **24.68%** exact-course accuracy even on its own
training data (top 3: 53.94%). The best possible rule under the same cross-validation reaches
17.91%; J48 is close to it. An 80% accuracy target is therefore not reachable with this target and
these inputs, by any classifier; reaching it would need a different target or different inputs,
which is a decision for the adviser.

The earlier 296-record model (17.97% vs ZeroR 7.23%, seeds not confirmed) and the 291-record
results are superseded and not comparable.

Related studies that explain the low accuracy, a learning curve, and recommendations:
`docs/methodology-notes.md` §10 and §11.
