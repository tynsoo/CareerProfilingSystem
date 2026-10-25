#!/usr/bin/env python3
"""
Model-selection experiment: CBF variants vs WEKA classifiers vs a CBF + classifier hybrid,
all on the SAME students, with every choice made on the training set only.

    python3 model_experiment.py --train train.csv --test test.csv --weka-jar path/to/weka.jar

train.csv / test.csv come from `python3 cbf_eval.py split` (they keep the original scores).
Needs Java and weka.jar (WEKA 3.8). Python standard library only.

Protocol (no test data is used for any choice):
  1. Stratified K-fold cross-validation (default K = 10, fixed seed) on train.csv only.
     Every classifier configuration (algorithm x hyper-parameters x feature encoding) is
     trained on K-1 folds and scored on the held-out fold, giving out-of-fold class
     probabilities for every training student.
  2. Selection on those out-of-fold results: the best configuration per algorithm, the best
     deployable classifier overall, the best CBF variant, and the hybrid weight w.
     Selection metric: MRR (mean reciprocal rank of the student's course). It uses the
     whole ranking, so it is less noisy than Top-1 accuracy on a small dataset with many
     classes; Top-1, macro-F1 and kappa are reported alongside it.
  3. Each selected model is trained once on all of train.csv and evaluated once on test.csv.

Feature encodings for the classifiers (the class is always the course):
  top3   : x/blank for the student's 3 highest types. What modules/ContentBasedFiltering/lib/PredictionModel.php runs now.
           Only 20 input patterns exist, so a tree can tell apart at most 20 groups.
  rank   : each type's rank within the student (1 = highest, ties share the average rank).
           Scale-free, so it works for any instrument (0-10 counts or the web app's 10-50).
  scores : the raw scores. REFERENCE ONLY: a model on raw scores is valid in the web app
           only if the dataset used the same instrument and scale as the web app.

CBF variants (no training; the variant is chosen on train.csv):
  student vector = actual scores (the web app) or top-3 binary;
  course vector  = binary (the web app) or a letter-order weighting from cbf_eval.ENCODINGS.

Hybrid: score(course) = w * CBF share + (1 - w) * classifier probability, where
CBF share = cosine(course) / sum of cosines (so both parts sum to 1 over the courses).
w is chosen on the out-of-fold training predictions; w = 1 is pure CBF, w = 0 is pure
classifier. If neither end is beaten, the simpler method should be kept.
"""
import argparse
import concurrent.futures as cf
import csv
import math
import os
import glob
import random
import shutil
import subprocess
import sys
import tempfile
from collections import Counter

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import cbf_eval as ce  # noqa: E402

DIM = ce.DIMENSIONS
COURSES = ce.COURSE_NAMES
EPS = ce.TIE_EPS

# Hyper-parameter grids. Small on purpose: the dataset is small, and every extra
# configuration is another chance to fit noise in the cross-validation.
ALGORITHMS = {
    "ZeroR": [("weka.classifiers.rules.ZeroR", [])],
    "J48": [("weka.classifiers.trees.J48", ["-C", c, "-M", m]) for c in ("0.1", "0.25", "0.5") for m in ("2", "5", "10")],
    "RandomForest": [("weka.classifiers.trees.RandomForest", ["-I", "200", "-K", k, "-S", "1"]) for k in ("0", "2")],
    "NaiveBayes": [("weka.classifiers.bayes.NaiveBayes", [])],
    "Logistic": [("weka.classifiers.functions.Logistic", ["-R", r]) for r in ("1.0E-8", "1.0", "10.0")],
    "SMO": [("weka.classifiers.functions.SMO", ["-C", c, "-M"]) for c in ("0.1", "1.0")],
    "IBk": [("weka.classifiers.lazy.IBk", ["-K", k]) for k in ("1", "5", "15")],
}
DEPLOYABLE = {"top3", "rank"}           # encodings the web app can rebuild from its own 10-50 scores
TREE_IMPORTABLE = {"J48"}               # algorithms db/import_weka_tree.php can import today


# ---------------------------------------------------------------- features

def ranks(vals):
    """Rank of each value within the student, 1 = highest; ties get the average rank."""
    order = sorted(range(len(vals)), key=lambda i: -vals[i])
    out = [0.0] * len(vals)
    i = 0
    while i < len(order):
        j = i
        while j + 1 < len(order) and abs(vals[order[j + 1]] - vals[order[i]]) < 1e-9:
            j += 1
        avg = (i + j) / 2 + 1
        for k in range(i, j + 1):
            out[order[k]] = avg
        i = j + 1
    return out


def has_scores(rows):
    return all(not ce.is_marked_row(r) for r in rows)


def features(row, enc):
    if enc == "top3":
        return ["x" if v else "blank" for v in ce.student_vector(row, "top3")]
    vals = [float(row[d]) for d in DIM]
    return [f"{v:g}" for v in (ranks(vals) if enc == "rank" else vals)]


def write_arff(path, rows, enc):
    classes = ",".join("'" + c.replace("'", "\\'") + "'" for c in COURSES)
    with open(path, "w", encoding="utf-8") as f:
        f.write(f"@RELATION riasec_{enc}\n\n")
        for d in DIM:
            f.write(f"@ATTRIBUTE {d} {{x,blank}}\n" if enc == "top3" else f"@ATTRIBUTE {d} NUMERIC\n")
        f.write(f"@ATTRIBUTE course {{{classes}}}\n\n@DATA\n")
        for r in rows:
            f.write(",".join(features(r, enc)) + ",'" + r["ActualCourse"].replace("'", "\\'") + "'\n")


def stratified_folds(rows, k, seed):
    """Fold index per row: each course's students are shuffled and dealt round-robin."""
    rng = random.Random(seed)
    by_class = {}
    for i, r in enumerate(rows):
        by_class.setdefault(r["ActualCourse"], []).append(i)
    fold = [0] * len(rows)
    nxt = 0
    for course in sorted(by_class):
        idx = by_class[course]
        rng.shuffle(idx)
        for i in idx:
            fold[i] = nxt % k
            nxt += 1
    return fold


# ---------------------------------------------------------------- WEKA

def resolve_java(java, jar):
    """The java program to run WEKA with. Tries --java, then PATH, then the Java that the
    Windows WEKA installer puts next to weka.jar (e.g. C:/Program Files/Weka-3-8-6/jre/.../bin/java.exe)."""
    jars = jar.split(os.pathsep)
    missing = [j for j in jars if not os.path.isfile(j)]
    if missing:
        raise SystemExit(f"weka.jar not found: {missing[0]}\n"
                         "Pass the full path, e.g. --weka-jar \"C:/Program Files/Weka-3-8-6/weka.jar\"")
    if os.path.isfile(java):
        return java
    found = shutil.which(java)
    if found:
        return found
    exe = "java.exe" if os.name == "nt" else "java"
    weka_dir = os.path.dirname(os.path.abspath(jars[0]))
    bundled = sorted(glob.glob(os.path.join(weka_dir, "jre", "**", "bin", exe), recursive=True))
    if bundled:
        return bundled[0]
    raise SystemExit(f"Java was not found ('{java}' is not on PATH and there is no jre folder next to {jars[0]}).\n"
                     "Pass WEKA's own Java with --java, e.g. --java \"C:/Program Files/Weka-3-8-6/jre/zulu17.../bin/java.exe\"")


def run_weka(java, jar, cls, opts, train_arff, test_arff):
    """Train on train_arff, predict test_arff; returns [{course: probability}] in test order."""
    cmd = [java, "-Xmx1g", "-cp", jar, cls, *opts, "-t", train_arff, "-T", test_arff, "-o",
           "-classifications", "weka.classifiers.evaluation.output.prediction.CSV -distribution"]
    p = subprocess.run(cmd, capture_output=True, text=True)
    if p.returncode != 0:
        msg = "\n".join(l for l in p.stderr.splitlines() if "JAVA_TOOL_OPTIONS" not in l)[:600]
        raise RuntimeError(f"WEKA failed for {cls} {' '.join(opts)}:\n{msg}")
    with tempfile.NamedTemporaryFile("w", suffix=".txt", delete=False, encoding="utf-8") as t:
        t.write(p.stdout)
    try:
        preds = ce.read_weka_predictions(t.name)
    finally:
        os.unlink(t.name)
    out = []
    for _, predicted, dist in preds:
        if dist is None:  # no distribution: put all mass on the predicted class
            dist = {c: float(c == predicted) for c in COURSES}
        out.append(dist)
    return out


# ---------------------------------------------------------------- metrics

def rank_stats(scores, actual, k):
    """Tie-aware expected Hit@1, Hit@K, reciprocal rank and NDCG@K for one relevant course."""
    s = scores.get(actual, 0.0)
    better = sum(1 for v in scores.values() if v > s + EPS)
    t = sum(1 for v in scores.values() if abs(v - s) <= EPS)
    pos = range(better + 1, better + t + 1)
    hit = lambda kk: min(max(kk - better, 0), t) / t
    rr = sum(1 / p for p in pos) / t
    ndcg = sum(1 / math.log2(p + 1) for p in pos if p <= k) / t
    return hit(1), hit(k), rr, ndcg


def argmax(scores):
    """Top course; ties go to catalogue order (only used for classification metrics/coverage)."""
    best = max(scores.values())
    return next(c for c in COURSES if abs(scores[c] - best) <= EPS)


def top_k(scores, k):
    return sorted(COURSES, key=lambda c: (-scores[c], COURSES.index(c)))[:k]


def classification(actuals, preds):
    labels = sorted(set(actuals))
    f1s, support = [], []
    for c in labels:
        tp = sum(1 for a, p in zip(actuals, preds) if a == c and p == c)
        fp = sum(1 for a, p in zip(actuals, preds) if a != c and p == c)
        fn = sum(1 for a, p in zip(actuals, preds) if a == c and p != c)
        prec = tp / (tp + fp) if tp + fp else 0.0
        rec = tp / (tp + fn) if tp + fn else 0.0
        f1s.append(2 * prec * rec / (prec + rec) if prec + rec else 0.0)
        support.append(tp + fn)
    n = len(actuals)
    acc = sum(a == p for a, p in zip(actuals, preds)) / n
    pa = Counter(actuals)
    pp = Counter(preds)
    pe = sum(pa[c] * pp[c] for c in set(pa) | set(pp)) / (n * n)
    return {"accuracy": acc, "macroF1": sum(f1s) / len(f1s),
            "weightedF1": sum(f * s for f, s in zip(f1s, support)) / n,
            "kappa": (acc - pe) / (1 - pe) if pe < 1 else 0.0}


def evaluate(score_rows, actuals, k, deterministic_preds=None):
    """score_rows: one {course: score} per student. Returns ranking + classification metrics."""
    st = [rank_stats(s, a, k) for s, a in zip(score_rows, actuals)]
    n = len(st)
    m = {"Top1": sum(x[0] for x in st) / n, f"Hit@{k}": sum(x[1] for x in st) / n,
         "MRR": sum(x[2] for x in st) / n, f"NDCG@{k}": sum(x[3] for x in st) / n}
    m[f"Precision@{k}"] = m[f"Hit@{k}"] / k
    m[f"F1@{k}"] = 2 * m[f"Precision@{k}"] * m[f"Hit@{k}"] / (m[f"Precision@{k}"] + m[f"Hit@{k}"]) if m[f"Hit@{k}"] else 0.0
    m["Coverage@K"] = len({c for s in score_rows for c in top_k(s, k)}) / len(COURSES)
    preds = deterministic_preds or [argmax(s) for s in score_rows]
    m.update(classification(actuals, preds))
    m["_rr"] = [x[2] for x in st]
    m["_top1"] = [x[0] for x in st]
    return m


# ---------------------------------------------------------------- CBF

def cbf_scores(row, student_scale, course_enc):
    svec = ce.student_vector(row, student_scale)
    cvecs = ce.course_vectors(ce.ENCODINGS[course_enc])
    return {c: ce.cosine(svec, cvecs[c]) for c in COURSES}


def cbf_levels(cos):
    """CBF candidate sets from cosine scores: (Best RIASEC Match, Alternatives), as the web app."""
    levels = sorted({round(v, 9) for v in cos.values()}, reverse=True)
    best = {c for c, v in cos.items() if round(v, 9) == levels[0]}
    alt = {c for c, v in cos.items() if len(levels) > 1 and round(v, 9) == levels[1]}
    return best, alt


def filtered(cos, prob, mode):
    """'CBF selects, classifier ranks': courses in the CBF candidate set come first, ordered by
    the classifier's probability (equal probabilities fall back to cosine); every other course
    follows in cosine order. mode = 'best' (Best RIASEC Match) or 'best+alt' (plus Alternatives).
    Every course shown first is therefore RIASEC-aligned by construction."""
    best, alt = cbf_levels(cos)
    cand = best | alt if mode == "best+alt" else best
    return {c: (2.0 + prob[c] + 1e-6 * cos[c]) if c in cand else cos[c] for c in COURSES}


def confirm(cos, prob):
    """The web app's proposed Final Best Match rule, "CBF selects, J48 confirms": J48's single
    predicted course (the most probable class, as the imported tree outputs it) goes first ONLY
    if it is in the CBF Best RIASEC Match; otherwise the CBF ranking is used unchanged.
    Returns (scores, confirmed?)."""
    label = max(COURSES, key=lambda c: (prob[c], -COURSES.index(c)))
    best, _ = cbf_levels(cos)
    if label in best:
        out = dict(cos)
        out[label] = 2.0
        return out, True
    return dict(cos), False


def share(scores):
    total = sum(scores.values())
    return {c: (v / total if total > 0 else 1 / len(COURSES)) for c, v in scores.items()}


# ---------------------------------------------------------------- experiment

def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--train", required=True)
    ap.add_argument("--test", required=True)
    ap.add_argument("--weka-jar", required=True, help="weka.jar (add dependency jars separated by the OS path separator)")
    ap.add_argument("--java", default="java")
    ap.add_argument("--folds", type=int, default=10)
    ap.add_argument("--seed", type=int, default=1)
    ap.add_argument("--k", type=int, default=3)
    ap.add_argument("--workers", type=int, default=max(1, (os.cpu_count() or 2) - 1))
    ap.add_argument("--algorithms", default=",".join(ALGORITHMS), help="comma-separated subset of: " + ",".join(ALGORITHMS))
    ap.add_argument("--report", default="experiment_report.md")
    a = ap.parse_args()
    a.java = resolve_java(a.java, a.weka_jar)
    print(f"Java: {a.java}")

    train = ce.read_students(a.train, quiet=True)
    test = ce.read_students(a.test, quiet=True)
    k = a.k
    encs = ["top3", "rank", "scores"] if has_scores(train) and has_scores(test) else ["top3"]
    algos = [x.strip() for x in a.algorithms.split(",") if x.strip()]
    y_train = [r["ActualCourse"] for r in train]
    y_test = [r["ActualCourse"] for r in test]
    folds = stratified_folds(train, a.folds, a.seed)
    work = tempfile.mkdtemp(prefix="riasec_exp_")
    print(f"train={len(train)} test={len(test)} courses(train)={len(set(y_train))} folds={a.folds} seed={a.seed} K={k}")
    print(f"encodings={encs} algorithms={algos}  (working files in {work})\n")

    # Fold files, per encoding.
    files = {}
    for enc in encs:
        for f in range(a.folds):
            tr = [r for r, g in zip(train, folds) if g != f]
            te = [r for r, g in zip(train, folds) if g == f]
            files[(enc, f)] = (os.path.join(work, f"{enc}_f{f}_tr.arff"), os.path.join(work, f"{enc}_f{f}_te.arff"))
            write_arff(files[(enc, f)][0], tr, enc)
            write_arff(files[(enc, f)][1], te, enc)
        files[(enc, "full")] = (os.path.join(work, f"{enc}_train.arff"), os.path.join(work, f"{enc}_test.arff"))
        write_arff(files[(enc, "full")][0], train, enc)
        write_arff(files[(enc, "full")][1], test, enc)

    # Check that WEKA runs at all before starting hundreds of runs.
    run_weka(a.java, a.weka_jar, "weka.classifiers.rules.ZeroR", [], *files[(encs[0], 0)])

    # 1. Cross-validation on train only: out-of-fold probabilities for every configuration.
    configs = [(alg, cls, opts, enc) for alg in algos for cls, opts in ALGORITHMS[alg] for enc in encs]
    jobs = {}
    with cf.ThreadPoolExecutor(a.workers) as ex:
        for ci, (alg, cls, opts, enc) in enumerate(configs):
            for f in range(a.folds):
                jobs[ex.submit(run_weka, a.java, a.weka_jar, cls, opts, *files[(enc, f)])] = (ci, f)
        oof = {ci: [None] * len(train) for ci in range(len(configs))}
        done = 0
        for fut in cf.as_completed(jobs):
            ci, f = jobs[fut]
            idx = [i for i, g in enumerate(folds) if g == f]
            for i, dist in zip(idx, fut.result()):
                oof[ci][i] = dist
            done += 1
            if done % 50 == 0 or done == len(jobs):
                print(f"  cross-validation runs: {done}/{len(jobs)}", flush=True)

    cv = {ci: evaluate(oof[ci], y_train, k) for ci in oof}
    best_per = {}
    for ci, (alg, cls, opts, enc) in enumerate(configs):
        key = (alg, enc)
        if key not in best_per or cv[ci]["MRR"] > cv[best_per[key]]["MRR"] + 1e-12:
            best_per[key] = ci

    # CBF variants, scored on train (no fitting; only the variant is chosen here).
    scales = (["scores"] if "scores" in encs else []) + ["top3"]
    cbf_variants = [(sc, enc) for sc in scales for enc in ("ranksum", "binary", "proposed", "steep", "flat")]
    cbf_train = {v: evaluate([cbf_scores(r, *v) for r in train], y_train, k) for v in cbf_variants}
    current_cbf = (scales[0], "binary")  # what the web app does (rank-sum 3-2-1 is a comparison only; not approved)
    best_cbf = max(cbf_variants, key=lambda v: cbf_train[v]["MRR"])

    # Best deployable classifier (chosen on CV MRR only).
    deploy = [ci for ci in best_per.values() if configs[ci][3] in DEPLOYABLE and configs[ci][0] != "ZeroR"]
    best_clf = max(deploy, key=lambda ci: cv[ci]["MRR"]) if deploy else None

    # Hybrid weight w on out-of-fold predictions.
    hybrid_w = None
    res = {}
    if best_clf is not None:
        cbf_tr = [share(cbf_scores(r, *current_cbf)) for r in train]
        grid = [i / 10 for i in range(11)]
        for w in grid:
            mixed = [{c: w * q[c] + (1 - w) * p[c] for c in COURSES} for q, p in zip(cbf_tr, oof[best_clf])]
            res[w] = evaluate(mixed, y_train, k)["MRR"]
        hybrid_w = max(grid, key=lambda w: (round(res[w], 9), w))  # a tie keeps the larger CBF share (simpler)

    # "CBF selects, classifier ranks": the candidate-set mode is chosen on out-of-fold predictions,
    # for the selected classifier and for the best J48 (the only model the web app imports today).
    cos_tr = [cbf_scores(r, *current_cbf) for r in train]
    j48_cis = [ci for (alg_, enc_), ci in best_per.items() if alg_ == "J48" and enc_ in DEPLOYABLE]
    best_j48 = max(j48_cis, key=lambda ci: cv[ci]["MRR"]) if j48_cis else None
    filt = {}
    for ci in {x for x in (best_clf, best_j48) if x is not None}:
        cvs = {mode: evaluate([filtered(c, p, mode) for c, p in zip(cos_tr, oof[ci])], y_train, k)["MRR"] for mode in ("best", "best+alt")}
        mode = max(cvs, key=lambda m: (round(cvs[m], 9), m == "best"))
        filt[ci] = (mode, cvs[mode])

    # 2. Final: train each selected configuration once on all of train, evaluate once on test.
    finals = {}
    with cf.ThreadPoolExecutor(a.workers) as ex:
        fut = {ex.submit(run_weka, a.java, a.weka_jar, configs[ci][1], configs[ci][2], *files[(configs[ci][3], "full")]): ci
               for ci in best_per.values()}
        for f_ in cf.as_completed(fut):
            finals[fut[f_]] = f_.result()

    rows = []  # (group, name, settings, cv_metrics or None, test_metrics)
    for sc, enc in cbf_variants:
        name = f"CBF student={sc} course={enc}"
        tag = " (web app)" if (sc, enc) == current_cbf else ""
        rows.append(("CBF", name + tag, "", cbf_train[(sc, enc)], evaluate([cbf_scores(r, sc, enc) for r in test], y_test, k)))
    for (alg, enc), ci in sorted(best_per.items(), key=lambda kv: (kv[0][1], kv[0][0])):
        dist = finals[ci]
        preds = [argmax(d) for d in dist]
        rows.append(("Classifier", f"{alg} [{enc}]", " ".join(configs[ci][2]) or "-", cv[ci], evaluate(dist, y_test, k, preds)))
    if best_clf is not None:
        cbf_te = [share(cbf_scores(r, *current_cbf)) for r in test]
        mixed = [{c: hybrid_w * q[c] + (1 - hybrid_w) * p[c] for c in COURSES} for q, p in zip(cbf_te, finals[best_clf])]
        alg, _, opts, enc = configs[best_clf]
        rows.append(("Hybrid", f"w={hybrid_w:.1f} x CBF(web app) + {1 - hybrid_w:.1f} x {alg} [{enc}]", " ".join(opts), {"MRR": res[hybrid_w]}, evaluate(mixed, y_test, k)))

    cos_te = [cbf_scores(r, *current_cbf) for r in test]
    # "CBF selects, J48 confirms" with the best top3 J48 (the format the web app runs).
    j48_top3 = [ci for (alg_, enc_), ci in best_per.items() if alg_ == "J48" and enc_ == "top3"]
    confirm_note = None
    if j48_top3:
        cj = j48_top3[0]
        cv_conf = [confirm(c, p) for c, p in zip(cos_tr, oof[cj])]
        te_conf = [confirm(c, p) for c, p in zip(cos_te, finals[cj])]
        m_cv = evaluate([x[0] for x in cv_conf], y_train, k)
        m_te = evaluate([x[0] for x in te_conf], y_test, k)
        rows.append(("Confirm", f"CBF Best Match, confirmed by J48 [top3] (proposed Final Best Match)", " ".join(configs[cj][2]),
                     {"MRR": m_cv["MRR"]}, m_te))
        base_cv = cbf_train[current_cbf]
        base_te = [t for g, n, s_, c, t in rows if g == "CBF" and "(web app)" in n][0]
        lo, hi = ce.paired_bootstrap(m_te["_rr"], base_te["_rr"])
        lo1, hi1 = ce.paired_bootstrap(m_te["_top1"], base_te["_top1"])
        confirm_note = (
            f"**Proposed Final Best Match rule (CBF selects, J48 [top3] {' '.join(configs[cj][2])} confirms):** "
            f"J48's course was inside the Best RIASEC Match for {sum(x[1] for x in cv_conf)}/{len(cv_conf)} training students "
            f"(out-of-fold) and {sum(x[1] for x in te_conf)}/{len(te_conf)} test students. "
            f"Train CV MRR {m_cv['MRR']:.3f} vs CBF {base_cv['MRR']:.3f}; Top-1 {m_cv['Top1']:.3f} vs {base_cv['Top1']:.3f}. "
            f"Test MRR {m_te['MRR']:.3f} vs CBF {base_te['MRR']:.3f} (difference {m_te['MRR'] - base_te['MRR']:+.3f}, "
            f"95% CI [{lo:+.3f}, {hi:+.3f}]); Top-1 difference {m_te['Top1'] - base_te['Top1']:+.3f}, 95% CI [{lo1:+.3f}, {hi1:+.3f}].")
    for ci, (mode, cvm) in filt.items():
        alg, _, opts, enc = configs[ci]
        rows.append(("Filtered", f"CBF {mode} -> ranked by {alg} [{enc}]", " ".join(opts), {"MRR": cvm},
                     evaluate([filtered(c, p, mode) for c, p in zip(cos_te, finals[ci])], y_test, k)))

    # Baselines on test.
    maj = Counter(y_train).most_common(1)[0][0]
    rand = 1 / len(COURSES)

    # 3. Report.
    cols = ["Top1", f"Hit@{k}", "MRR", f"NDCG@{k}", "macroF1", "kappa", "Coverage@K"]
    def line(m):
        return " | ".join(f"{m[c]:.3f}" for c in cols)
    out = []
    out.append("# CBF vs WEKA model-selection experiment\n")
    out.append(f"- Train: {len(train)} students, test: {len(test)} students, {len(set(y_train))} courses in train; "
               f"{a.folds}-fold stratified CV on train only (seed {a.seed}); K = {k}.")
    out.append(f"- Selection metric: cross-validated MRR on train. Test data was used once, after every choice was made.")
    out.append(f"- Baselines on test: random Top-1 = {rand:.3f}, Hit@{k} = {k * rand:.3f}; "
               f"most frequent course ({maj}) Top-1 = {sum(y == maj for y in y_test) / len(y_test):.3f}.\n")
    out.append("## Test-set results (each configuration chosen on train)\n")
    out.append("| Method | Settings | " + " | ".join(cols) + " | CV MRR (train) |")
    out.append("|---|---|" + "---|" * (len(cols) + 1))
    for grp, name, settings, cvm, tm in rows:
        out.append(f"| {name} | {settings} | {line(tm)} | {cvm['MRR']:.3f} |" if cvm else f"| {name} | {settings} | {line(tm)} | - |")
    out.append("")
    if best_clf is not None:
        alg, _, opts, enc = configs[best_clf]
        out.append(f"**Selected on train:** CBF variant = student {best_cbf[0]}, course {best_cbf[1]} "
                   f"(the web app uses student {current_cbf[0]}, course binary); "
                   f"deployable classifier = {alg} {' '.join(opts)} on '{enc}' features; hybrid w = {hybrid_w:.1f}.")
        base = [t for g, n, s, c, t in rows if g == "CBF" and "(web app)" in n][0]
        clf_t = [t for g, n, s, c, t in rows if n == f"{alg} [{enc}]"][0]
        lo, hi = ce.paired_bootstrap(clf_t["_rr"], base["_rr"])
        out.append(f"\nMRR difference on test, {alg} [{enc}] - CBF (web app): "
                   f"{clf_t['MRR'] - base['MRR']:+.3f}, 95% CI [{lo:+.3f}, {hi:+.3f}] (paired bootstrap over students).")
        out.append("\nHybrid, cross-validated MRR on train by CBF weight w (w = 0 is the classifier alone, w = 1 the CBF alone): "
                   + ", ".join(f"{w:.1f}: {v:.3f}" for w, v in sorted(res.items())))
        out.append("\n'CBF X -> ranked by Y' rows: only courses in the CBF Best RIASEC Match (or Best Match + "
                   "Alternatives) can come first, ordered by the classifier's probability; X was chosen on train.")
        if confirm_note:
            out.append("\n" + confirm_note)
        j48 = [ci for (alg_, enc_), ci in best_per.items() if alg_ == "J48" and enc_ in DEPLOYABLE]
        if j48:
            bj = max(j48, key=lambda ci: cv[ci]["MRR"])
            out.append(f"\nBest J48 (the only algorithm db/import_weka_tree.php imports today): "
                       f"{' '.join(configs[bj][2])} on '{configs[bj][3]}' features, CV MRR {cv[bj]['MRR']:.3f}. "
                       f"Import it with --features {configs[bj][3]}.")
        if hybrid_w in (0.0, 1.0):
            out.append(f"\nThe hybrid weight chosen on train is w = {hybrid_w:.0f}: combining the two did not improve the "
                       f"cross-validated MRR, so the simpler {'CBF' if hybrid_w == 1 else 'classifier'} should be kept.")
    out.append("\nNotes: Top1/Hit/MRR/NDCG are tie-aware expected values (CBF ties are common: the 32 programs "
               "have only 11 distinct binary course vectors, 22 with the rank-sum weights, which are not used). With one relevant course per student, MAP = MRR, "
               f"Recall@{k} = Hit@{k} and Precision@{k} = Hit@{k}/{k}. macroF1/kappa use each method's single top "
               "course (CBF ties broken by catalogue order). 'scores' features are a reference only: the web app "
               "can use them only if the dataset came from the same instrument and scale.")
    report = "\n".join(out)
    print("\n" + report)
    with open(a.report, "w", encoding="utf-8") as f:
        f.write(report + "\n")
    with open(os.path.splitext(a.report)[0] + "_cv.csv", "w", newline="", encoding="utf-8") as f:
        w = csv.writer(f)
        w.writerow(["algorithm", "options", "encoding", *cols, f"Precision@{k}", f"F1@{k}", "accuracy", "weightedF1"])
        for ci, (alg, cls, opts, enc) in enumerate(configs):
            m = cv[ci]
            w.writerow([alg, " ".join(opts), enc, *[f"{m[c]:.4f}" for c in cols], f"{m[f'Precision@{k}']:.4f}",
                        f"{m[f'F1@{k}']:.4f}", f"{m['accuracy']:.4f}", f"{m['weightedF1']:.4f}"])
    print(f"\nReport: {a.report}; every cross-validated configuration: {os.path.splitext(a.report)[0]}_cv.csv")


if __name__ == "__main__":
    main()
