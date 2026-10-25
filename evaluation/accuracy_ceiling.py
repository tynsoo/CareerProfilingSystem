"""Highest accuracy any model can reach on the top-3 binary RIASEC inputs.

The Prediction Module's inputs are six 1/0 values (1 = one of the student's three highest
RIASEC types), so there are only 20 possible input patterns. Every model that uses only
these inputs, J48 included, gives the same prediction to every student with the same
pattern. For each pattern it can at best predict the course most of those students chose,
so this script computes, from the data itself:

  ceiling       best possible accuracy even when tested on the students it learned from
                (sum over patterns of the most common course's count, / students)
  top-3 ceiling the same for "the student's course is among the model's three courses"
  cross-val     the pattern-majority rule (what a fully grown tree on these inputs does)
                under stratified 10-fold cross-validation, seeds 1-10, next to ZeroR

Run on your own computer; the data file stays there (never commit it):

  python accuracy_ceiling.py --data riasec_new.csv
  python accuracy_ceiling.py --data all.arff --target college

CSV: a student column, R,I,A,S,E,C (scores, 1/0 or x/blank) and a course column, as for
cbf_eval.py. Scores become top-3 binary with ties in R, I, A, S, E, C order (the app's rule).
ARFF: R..C as 0/1 (or yes/no, x/blank) and the course as the class attribute (last, or one
named "Actual Course"/"course"). Rows with "Undecided" are left out unless --keep-undecided.
"""
import argparse
import csv
import math
import os
import random
import statistics
import sys
from collections import Counter, defaultdict

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from cbf_eval import COURSE_LOOKUP, DIMENSIONS, _key, read_students, student_vector  # noqa: E402

LETTER_TRUE = {"1", "1.0", "x", "yes", "y", "true", "t", "✓"}
LETTER_FALSE = {"0", "0.0", "", "no", "n", "false", "f", "-"}


def course_name(label):
    """The course list's spelling when known (merges e.g. 'BS Pyschology' with 'BS Psychology')."""
    return COURSE_LOOKUP.get(_key(label), label.strip())


def read_arff(path):
    """(pattern, label) pairs from an ARFF file; rows with missing values are skipped and counted."""
    attrs, rows, skipped, in_data = [], [], 0, False
    with open(path, encoding="utf-8-sig") as f:
        for line in f:
            s = line.strip()
            if not s or s.startswith("%"):
                continue
            low = s.lower()
            if not in_data and low.startswith("@attribute"):
                rest = s[len("@attribute"):].strip()
                name = rest[1:rest.index(rest[0], 1)] if rest[0] in "'\"" else rest.split()[0]
                attrs.append(name)
            elif low.startswith("@data"):
                in_data = True
            elif in_data:
                rows.append([c.strip().strip('"') for c in next(csv.reader([s], quotechar="'", skipinitialspace=True))])
    lower = [a.strip().lower() for a in attrs]
    try:
        cols = [lower.index(d.lower()) for d in DIMENSIONS]
    except ValueError:
        raise SystemExit(f"{path}: needs attributes named R, I, A, S, E, C (found {attrs})")
    named = [i for i, a in enumerate(lower) if a.replace(" ", "") in ("actualcourse", "course")]
    cls = named[0] if named else len(attrs) - 1
    out = []
    for r in rows:
        cells = [r[i].lower() for i in cols]
        if r[cls] == "?" or any(c == "?" for c in cells):
            skipped += 1
            continue
        bad = [c for c in cells if c not in LETTER_TRUE | LETTER_FALSE]
        if bad:
            raise SystemExit(f"{path}: R..C must be 1/0 (top-3 binary); found {bad[:3]}. Use the CSV with scores instead.")
        out.append((tuple(1 if c in LETTER_TRUE else 0 for c in cells), r[cls]))
    return out, skipped


def load(path, keep_undecided=False):
    """[(pattern, course)] with the course list's spelling; top-3 binary patterns."""
    skipped = 0
    if path.lower().endswith(".arff"):
        pairs, skipped = read_arff(path)
    else:
        rows = read_students(path, drop=() if keep_undecided else ("Undecided",))
        pairs = [(tuple(int(v) for v in student_vector(r, "top3")), r["ActualCourse"]) for r in rows]
    if not keep_undecided:
        pairs = [(p, c) for p, c in pairs if _key(c) != "UNDECIDED"]
    not_three = sum(1 for p, _ in pairs if sum(p) != 3)
    return [(p, course_name(c)) for p, c in pairs], skipped, not_three


def college_map(path):
    with open(path, newline="", encoding="utf-8-sig") as f:
        return {course_name(r["Course"]): r["College"] for r in csv.DictReader(f)}


def ceilings(pairs):
    """(exact ceiling, top-3 ceiling, per-pattern rows) when tested on the same students."""
    by = defaultdict(Counter)
    for p, c in pairs:
        by[p][c] += 1
    n = len(pairs)
    detail = []
    for p, counts in sorted(by.items(), key=lambda kv: -sum(kv[1].values())):
        top = counts.most_common(3)
        detail.append((p, sum(counts.values()), len(counts), top[0][0], top[0][1], sum(k for _, k in top)))
    return sum(d[4] for d in detail) / n, sum(d[5] for d in detail) / n, detail


def stratified_folds(labels, k, seed):
    """Weka-style: shuffle with the seed, sort by class, deal round-robin into k folds."""
    idx = list(range(len(labels)))
    random.Random(seed).shuffle(idx)
    idx.sort(key=lambda i: labels[i])  # stable: shuffled order kept within a class
    folds = [[] for _ in range(k)]
    for j, i in enumerate(idx):
        folds[j % k].append(i)
    return folds


def cross_validate(pairs, k=10, seeds=range(1, 11)):
    """Mean and sd over seeds of (pattern-majority accuracy, its top-3 accuracy, ZeroR accuracy)."""
    labels = [c for _, c in pairs]
    runs = []
    for seed in seeds:
        right = right3 = zero = 0
        for test in stratified_folds(labels, k, seed):
            held = set(test)
            overall = Counter(labels[i] for i in range(len(pairs)) if i not in held)
            by = defaultdict(Counter)
            for i in range(len(pairs)):
                if i not in held:
                    by[pairs[i][0]][labels[i]] += 1
            # Ties between courses go to the overall more common one, then the name, so runs repeat exactly.
            rank = lambda cnt: sorted(cnt, key=lambda c: (-cnt[c], -overall[c], c))  # noqa: E731
            zero_label = rank(overall)[0]
            for i in test:
                seen = by.get(pairs[i][0])
                guesses = rank(seen)[:3] if seen else rank(overall)[:3]
                right += guesses[0] == labels[i]
                right3 += labels[i] in guesses
                zero += zero_label == labels[i]
        n = len(pairs)
        runs.append((right / n, right3 / n, zero / n))
    cols = list(zip(*runs))
    return [(statistics.mean(c), statistics.stdev(c) if len(c) > 1 else 0.0) for c in cols]


def pattern_code(p):
    return "".join(d for d, v in zip(DIMENSIONS, p) if v)


def report(pairs, target, target_floor=0.80):
    n = len(pairs)
    labels = Counter(c for _, c in pairs)
    exact, top3, detail = ceilings(pairs)
    (cv, cv_sd), (cv3, cv3_sd), (zr, zr_sd) = cross_validate(pairs)
    print(f"\nTarget: {target}   Students: {n}   Classes: {len(labels)}   Input patterns used: {len(detail)} of 20")
    print(f"Largest class: {labels.most_common(1)[0][0]} ({labels.most_common(1)[0][1]} students)\n")
    print(f"  Ceiling, exact {target} (even tested on its own training data): {exact:7.2%}")
    print(f"  Ceiling, {target} among the model's top 3:                     {top3:7.2%}")
    print(f"  10-fold CV x10 seeds, pattern-majority rule:  {cv:7.2%}  (sd {cv_sd:.2%})")
    print(f"  10-fold CV x10 seeds, same rule, top 3:       {cv3:7.2%}  (sd {cv3_sd:.2%})")
    print(f"  10-fold CV x10 seeds, ZeroR (baseline):       {zr:7.2%}  (sd {zr_sd:.2%})")
    need = math.ceil(target_floor * n - 1e-9)
    best = round(exact * n)
    print(f"\n  {target_floor:.0%} of {n} students = {need} correct. The most any model on these inputs can get right: {best}.")
    print("  " + (f"{target_floor:.0%} is NOT reachable with these inputs and this target, by any classifier."
                  if exact < target_floor else f"{target_floor:.0%} is not ruled out by the ceiling; only a cross-validated run can show it."))
    print(f"\n  Per input pattern (top 3 types): students, distinct {target}s, most common {target} and its share")
    for p, cnt, distinct, best_label, best_cnt, _ in detail:
        print(f"    {pattern_code(p) or '(none)':<4} {cnt:4d}  {distinct:3d}  {best_label[:52]:<52} {best_cnt:4d} ({best_cnt / cnt:.0%})")


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--data", required=True, help="student CSV or ARFF (stays on this computer)")
    ap.add_argument("--target", choices=["course", "college", "both"], default="both")
    ap.add_argument("--courses", default=os.path.join(os.path.dirname(os.path.abspath(__file__)), "course_profiles.csv"),
                    help="course -> college list (for --target college)")
    ap.add_argument("--keep-undecided", action="store_true", help="keep rows whose course is Undecided")
    a = ap.parse_args()
    pairs, skipped, not_three = load(a.data, a.keep_undecided)
    if not pairs:
        raise SystemExit("No usable rows.")
    print(f"Read {len(pairs)} students from {os.path.basename(a.data)}" + (f"; skipped {skipped} with missing values" if skipped else ""))
    if not_three:
        print(f"WARNING: {not_three} row(s) do not have exactly three 1s; the deployed model always has three.")
    if a.target in ("course", "both"):
        report(pairs, "course")
    if a.target in ("college", "both"):
        colleges = college_map(a.courses)
        missing = sorted({c for _, c in pairs if c not in colleges})
        if missing:
            print(f"\nCollege not known for {missing}; those students are left out of the college figures.")
        report([(p, colleges[c]) for p, c in pairs if c in colleges], "college")
    print("\nThese are limits of the data, not results of a trained model. Report the J48 result from Weka's")
    print("Experimenter (10-fold CV, 10 runs) next to ZeroR, and these ceilings to explain the gap.")


if __name__ == "__main__":
    main()
