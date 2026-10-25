#!/usr/bin/env python3
"""
CBF + Cosine Similarity evaluation toolkit (Python 3 standard library only).

Offline research companion to the web app's recommender (modules/ContentBasedFiltering/lib/CBFEngine.php).
It builds the course dataset, runs the CBF on student RIASEC profiles,
evaluates Top-N performance against ActualCourse, compares Holland-code rank
encodings, and prepares identical train/test splits for WEKA.

    python3 cbf_eval.py courses                                   -> course_profiles.csv
    python3 cbf_eval.py split   --students student_profiles.csv   -> train/test .csv + .arff
    python3 cbf_eval.py cbf     --students test.csv --k 3         -> cbf_results.csv + metrics
    python3 cbf_eval.py weights --students train.csv --k 3        -> encoding comparison
    python3 cbf_eval.py compare --train train.csv --students test.csv --weka j48_predictions.txt
                                                                  -> CBF vs J48 on the same test students
    python3 cbf_eval.py agreement --students riasec.csv --model ../config/prediction_model.json
                                                                  -> how often the imported WEKA model's course
                                                                     is also a CBF Best RIASEC Match, per student

Rows whose course is "Undecided" (or another --drop value) are removed when the file is read:
they are not a course, so neither method can recommend them. The .arff files give WEKA the
top 3 RIASEC types as x/blank (the format modules/ContentBasedFiltering/lib/PredictionModel.php runs in the web app); the
.csv files keep the original scores, so the CBF can use the student's actual profile.

LEAKAGE RULE: ActualCourse is read only by the evaluation functions. The
scoring function `rank_courses()` receives nothing but the six RIASEC scores.
"""
import argparse
import csv
import math
import os
import random
from collections import Counter

DIMENSIONS = ["R", "I", "A", "S", "E", "C"]  # fixed vector order

# Encodings for a 3-letter Holland code: weight of the 1st, 2nd and 3rd letter
# (absent letters = 0). These are ENGINEERING choices, not weights defined by
# Holland's theory. "binary" (letter present = 1) is what the web app uses
# (modules/ContentBasedFiltering/lib/CBFEngine.php). "ranksum" (3, 2, 1) turns the counselors'
# 1st/2nd/3rd order of the letters into weights; it is NOT approved (the counselors' 1-2-3 is a
# ranking order, not weights) and is kept only as an offline comparison, like the others in `weights`.
DEFAULT_ENCODING = "binary"
ENCODINGS = {
    "ranksum": (3.0, 2.0, 1.0),
    "proposed": (1.00, 0.67, 0.33),
    "binary": (1.00, 1.00, 1.00),
    "steep": (1.00, 0.50, 0.25),
    "flat": (1.00, 0.75, 0.50),
}

# 32 courses. OriginalHollandCode = code in the system's seed data
# (db/seed_programs.php, "verbatim from RIASEC_Program_Crosswalk.docx").
# FinalHollandCode = validator revision of August 7, 2026 (authoritative).
# Courses whose original differs from the revision: Tourism, Computer Engineering.
# See METHODOLOGY.md §2 for two originals that still need confirmation.
COURSES = [
    ("CAS", "BA Communication", "AES", "AES"),
    ("CAS", "B Multimedia Arts", "AER", "AER"),
    ("CCIS", "BS Computer Science", "IRC", "IRC"),
    ("CCIS", "BS Information Technology", "IRC", "IRC"),
    ("CHS", "BS Biology", "IRS", "IRS"),
    ("CHS", "BS Medical Technology", "IRC", "IRC"),
    ("CHS", "BS Pharmacy", "ISC", "ISC"),
    ("CHS", "BS Physical Therapy", "SIR", "SIR"),
    ("CHS", "BS Psychology", "SIA", "SIA"),
    ("CN", "BS Nursing", "SIR", "SIR"),
    ("ETYCB", "BS Accountancy", "CEI", "CEI"),
    ("ETYCB", "BS Accounting Information System", "CIE", "CIE"),
    ("ETYCB", "BSBA Major in Financial Management", "ECI", "ECI"),
    ("ETYCB", "BSBA Major in Operations Management", "EIC", "EIC"),
    ("ETYCB", "BSBA Major in Sustainability Management", "EIS", "EIS"),
    ("ETYCB", "BS Hospitality Management", "ESC", "ESC"),
    ("ETYCB", "BS Tourism Management", "EAS", "SEA"),
    ("ETYCB", "BS International Business", "ECS", "ECS"),
    ("ETYCB", "BS Business Analytics with Artificial Intelligence", "IEC", "IEC"),
    ("ETYCB", "BS Marketing", "EAS", "EAS"),
    ("MITL", "BS Architecture", "ARI", "ARI"),
    ("MITL", "BS Chemical Engineering", "IRC", "IRC"),
    ("MITL", "BS Civil Engineering", "RIC", "RIC"),
    ("MITL", "BS Mechanical Engineering", "RIC", "RIC"),
    ("MITL", "BS Electrical Engineering", "RIC", "RIC"),
    ("MITL", "BS Electronics Engineering", "RIC", "RIC"),
    ("MITL", "BS Industrial Engineering", "REC", "REC"),
    ("MITL", "BS Computer Engineering", "RIC", "ICR"),
    ("MIA", "BS Aeronautical Engineering", "RIC", "RIC"),
    ("MIA", "BS Aviation Management", "ERC", "ERC"),
    ("CMET", "BS Marine Engineering", "RIC", "RIC"),
    ("CMET", "BS Marine Transportation", "REC", "REC"),
]
COURSE_NAMES = [c[1] for c in COURSES]
TIE_EPS = 1e-12  # scores closer than this are treated as equal (identical course vectors)


# ---------------------------------------------------------------- vectors

def holland_to_vector(code, weights=ENCODINGS[DEFAULT_ENCODING]):
    """'IRC' -> [1, 1, 0, 0, 0, 1] (binary); [0.67, 1.00, 0, 0, 0, 0.33] with the proposed encoding."""
    code = code.strip().upper()
    if len(code) != 3 or len(set(code)) != 3 or any(c not in DIMENSIONS for c in code):
        raise ValueError(f"Invalid Holland code: {code!r}")
    vec = [0.0] * 6
    for rank, letter in enumerate(code):
        vec[DIMENSIONS.index(letter)] = weights[rank]
    return vec


MARK_YES = {"x", "yes", "y", "1", "\u2713"}   # cell values meaning "this letter applies"
MARK_NO = {"", "no", "n", "0", "-"}


def is_marked_row(row):
    """True when R..C cells are x/blank marks rather than numeric scores."""
    cells = [str(row[d]).strip().lower() for d in DIMENSIONS]
    return all(c in MARK_YES | MARK_NO for c in cells) and any(c in ("x", "", "yes", "no", "y", "n", "-", "\u2713") for c in cells)


def top_binary(vals, n=3):
    """1 for the n highest scores, 0 otherwise; ties keep R,I,A,S,E,C order (same as the app)."""
    order = sorted(range(6), key=lambda i: -vals[i])  # stable sort -> ties keep R..C order
    top = set(order[:n])
    return [1.0 if i in top else 0.0 for i in range(6)]


def third_place_tie(row):
    """True when the 3rd and 4th highest scores are equal (the top 3 is decided by letter order)."""
    vals = sorted((float(row[d]) for d in DIMENSIONS), reverse=True)
    return abs(vals[2] - vals[3]) < 1e-9


def student_vector(row, scale="auto"):
    """Student RIASEC result -> [R,I,A,S,E,C].

    auto   : x/blank cells -> 1/0 as given; numeric scores -> top-3 binary
    top3   : numeric scores -> 1 for the 3 highest, 0 otherwise
    scores : the actual scores as given (any non-negative scale). This is the web app's
             current CBF (modules/ContentBasedFiltering/lib/CBFEngine.php uses the actual profile); cosine ignores the
             unit, so totals, means or 0-10 counts on one scale all give the same ranking
    unit   : scores are already 0-1 (validated)
    raw50  : raw totals 10-50 divided by 50 (does NOT change cosine values)
    minmax : (raw - 10) / 40, so an all-"1" dimension becomes 0 (DOES change cosine)
    """
    if is_marked_row(row):
        return [1.0 if str(row[d]).strip().lower() in MARK_YES else 0.0 for d in DIMENSIONS]
    try:
        vals = [float(row[d]) for d in DIMENSIONS]
    except ValueError:
        raise ValueError(f"Student {row.get('StudentID')}: R..C must be x/blank marks or numbers")
    if scale in ("auto", "top3"):
        return top_binary(vals)
    if scale == "scores":
        if any(v < 0 for v in vals):
            raise ValueError(f"Student {row.get('StudentID')}: scores cannot be negative")
        return vals
    if scale == "unit":
        if any(v < 0 or v > 1 for v in vals):
            raise ValueError(f"Student {row.get('StudentID')}: scores must be 0-1 (use --scale raw50/minmax for raw totals)")
        return vals
    if any(v < 10 or v > 50 for v in vals):
        raise ValueError(f"Student {row.get('StudentID')}: raw scores must be 10-50")
    return [v / 50 for v in vals] if scale == "raw50" else [(v - 10) / 40 for v in vals]


def cosine(a, b):
    """(a . b) / (|a| |b|); 0.0 when either vector has zero magnitude."""
    dot = sum(x * y for x, y in zip(a, b))
    na = math.sqrt(sum(x * x for x in a))
    nb = math.sqrt(sum(y * y for y in b))
    return dot / (na * nb) if na > 0 and nb > 0 else 0.0


def course_vectors(weights):
    return {name: holland_to_vector(final, weights) for _, name, _, final in COURSES}


# ---------------------------------------------------------------- CBF

def rank_courses(svec, cvecs):
    """Score EVERY course and rank by cosine, highest first.

    Returns [(course, cosine, competition_rank)], ordered by score with ties
    broken by catalogue order (display only). Tied courses share a rank
    (1, 2, 2, 4 ...), because identical course vectors cannot be told apart.
    Only the student's RIASEC vector is used — never ActualCourse.
    """
    scored = [(name, cosine(svec, vec)) for name, vec in cvecs.items()]
    ordered = sorted(scored, key=lambda t: (-t[1], COURSE_NAMES.index(t[0])))
    out = []
    for name, s in ordered:
        rank = 1 + sum(1 for _, o in scored if o > s + TIE_EPS)
        out.append((name, s, rank))
    return out


def tie_aware(ranked, actual, k):
    """Expected Hit@K and reciprocal rank when ties are broken at random.

    If `better` courses score strictly higher than the actual course and `t`
    courses (including it) share its score, the actual course is equally likely
    to occupy any position better+1 .. better+t.
    """
    s = next(sc for name, sc, _ in ranked if name == actual)
    better = sum(1 for _, o, _ in ranked if o > s + TIE_EPS)
    t = sum(1 for _, o, _ in ranked if abs(o - s) <= TIE_EPS)
    hit = min(max(k - better, 0), t) / t
    rr = sum(1 / p for p in range(better + 1, better + t + 1)) / t
    return hit, rr, better + 1, t


def evaluate(students, cvecs, k, scale):
    """Top-N metrics. With exactly one relevant course per student:
    Recall@K == HitRate@K and Precision@K == HitRate@K / K."""
    hits, rrs, top1, code_hits = [], [], [], []
    final_code = {name: final for _, name, _, final in COURSES}
    for row in students:
        ranked = rank_courses(student_vector(row, scale), cvecs)
        hit, rr, _, _ = tie_aware(ranked, row["ActualCourse"], k)
        hits.append(hit)
        rrs.append(rr)
        top1.append(tie_aware(ranked, row["ActualCourse"], 1)[0])
        # Code-level hit: is the actual course's Holland code among the top-K courses' codes?
        code_hits.append(float(final_code[row["ActualCourse"]] in {final_code[n] for n, _, _ in ranked[:k]}))
    n = len(students)
    hr = sum(hits) / n
    return {
        "n": n, "k": k,
        f"HitRate@{k}": hr, f"Recall@{k}": hr, f"Precision@{k}": hr / k,
        "MRR": sum(rrs) / n, "Top1Accuracy(expected)": sum(top1) / n,
        f"CodeHit@{k}": sum(code_hits) / n,
        "_per_student_rr": rrs,
    }


def paired_bootstrap(a, b, iters=2000, seed=7):
    """95% CI of mean(a - b) over students, resampling students with replacement."""
    rng = random.Random(seed)
    n = len(a)
    diffs = sorted(sum(a[i] - b[i] for i in (rng.randrange(n) for _ in range(n))) / n for _ in range(iters))
    return diffs[int(0.025 * iters)], diffs[int(0.975 * iters)]


# ---------------------------------------------------------------- I/O

# Short names accepted in the course column (case, spaces, dots and hyphens ignored).
# Ambiguous abbreviations (e.g. BSMT = Medical Technology or Marine Transportation)
# are deliberately left out; use the full name for those.
# Short names, and misspellings found in the team's dataset and Weka model labels
# (the same ones config/course_aliases.php maps for the web app).
COURSE_ALIASES = {
    "BA Communication": ["BACOMM", "ABCOMM"],
    "B Multimedia Arts": ["BS Multimedia Arts", "BMMA", "BSMMA"],
    "BS Computer Science": ["BSCS"],
    "BS Information Technology": ["BSIT"],
    "BS Biology": ["BSBIO"],
    "BS Medical Technology": ["BSMEDTECH"],
    "BS Pharmacy": ["BSPHARMA"],
    "BS Physical Therapy": ["BSPT"],
    "BS Psychology": ["BSPSYCH", "BS Pyschology"],
    "BS Nursing": ["BSN"],
    "BS Accountancy": ["BSA"],
    "BS Accounting Information System": ["BSAIS"],
    "BSBA Major in Financial Management": ["BS Business Administration Major in Financial Management", "BSBAFM",
                                           "BS Business Administration Major in Financial Managements"],
    "BSBA Major in Operations Management": ["BS Business Administration Major in Operations Management", "BSBAOM",
                                            "BS Business Administration Major in Operations Managements"],
    "BSBA Major in Sustainability Management": ["BS Business Administration Major in Sustainability Management", "BSBASM"],
    "BS Hospitality Management": ["BSHM", "BS Hospitality Managment"],
    "BS Tourism Management": ["BSTM"],
    "BS International Business": ["BSIB"],
    "BS Architecture": ["BSARCH"],
    "BS Chemical Engineering": ["BSCHE"],
    "BS Civil Engineering": ["BSCE"],
    "BS Mechanical Engineering": ["BSME"],
    "BS Electrical Engineering": ["BSEE"],
    "BS Electronics Engineering": ["BSECE"],
    "BS Industrial Engineering": ["BSIE"],
    "BS Computer Engineering": ["BSCPE", "BSCOE"],
    "BS Aeronautical Engineering": ["BSAE", "BSAERO"],
    "BS Marine Engineering": ["BSMARE"],
}


def _key(text):
    return "".join(ch for ch in str(text).upper() if ch.isalnum())


COURSE_LOOKUP = {_key(n): n for n in COURSE_NAMES}
COURSE_LOOKUP.update({_key(a): n for n, aliases in COURSE_ALIASES.items() for a in aliases})


DROP_DEFAULT = ("Undecided",)


def read_students(path, drop=DROP_DEFAULT, quiet=False):
    """Read the student CSV. Accepts the adviser's layout: a blank or "Student" first header,
    lowercase r..c columns with x/blank cells, and a "course" column (full name or short name).
    Rows whose course is in `drop` (default: "Undecided") are removed and counted."""
    with open(path, newline="", encoding="utf-8-sig") as f:
        raw = list(csv.reader(f))
    if not raw:
        raise SystemExit(f"{path} is empty")
    alias = {"": "StudentID", "student": "StudentID", "studentid": "StudentID", "student_id": "StudentID", "id": "StudentID",
             "course": "ActualCourse", "actualcourse": "ActualCourse", **{d.lower(): d for d in DIMENSIONS}}
    header = [alias.get(h.strip().lower().replace(" ", ""), h.strip()) for h in raw[0]]
    rows = [dict(zip(header, r)) for r in raw[1:] if any(c.strip() for c in r)]
    if "StudentID" not in header:  # anonymised files (scores and course only): number the rows
        for n, r in enumerate(rows, 1):
            r["StudentID"] = f"row{n}"
    for r in rows:  # columns missing from the file (e.g. letters nobody marked) count as blank
        for d in DIMENSIONS:
            r.setdefault(d, "")
    need = {"StudentID", "ActualCourse"}
    if not rows or not need <= set(rows[0]):
        raise SystemExit(f"{path} must have a student column, R,I,A,S,E,C columns and a course column")
    drop_keys = {_key(d) for d in drop}
    dropped = [r for r in rows if _key(r["ActualCourse"]) in drop_keys]
    rows = [r for r in rows if _key(r["ActualCourse"]) not in drop_keys]
    if dropped and not quiet:
        print(f"Removed {len(dropped)} row(s) with course {sorted({r['ActualCourse'] for r in dropped})} from {os.path.basename(path)}")
    unknown = sorted({r["ActualCourse"] for r in rows if _key(r["ActualCourse"]) not in COURSE_LOOKUP})
    if unknown:
        raise SystemExit(f"Course values not recognised (use the full course name): {unknown}")
    for r in rows:
        r["ActualCourse"] = COURSE_LOOKUP[_key(r["ActualCourse"])]
    dupes = [s for s, c in Counter(r["StudentID"] for r in rows).items() if c > 1]
    if dupes:
        raise SystemExit(f"Duplicate StudentID values: {dupes[:5]}")
    return rows


def write_csv(path, header, rows):
    with open(path, "w", newline="", encoding="utf-8") as f:
        w = csv.writer(f)
        w.writerow(header)
        w.writerows(rows)


def write_arff(path, rows, scale, fmt="top3"):
    """WEKA file + ActualCourse nominal (all 32 classes declared so train/test headers match).
    StudentID is deliberately NOT an attribute.

    fmt="top3"    : R..C nominal {x,blank}: x = one of the student's top 3 types (the
                    adviser's format, which modules/ContentBasedFiltering/lib/PredictionModel.php runs in the web app)
    fmt="numeric" : R..C numeric, from student_vector(row, scale)
    fmt="binary"  : R..C numeric 0/1, 1 = one of the student's top 3 types (ties in R,I,A,S,E,C
                    order), class "Actual Course" with only the courses present: the format of the
                    deployed Prediction Module model (tools/weka/README.md)"""
    names = [c for c in COURSE_NAMES if c in {r["ActualCourse"] for r in rows}] if fmt == "binary" else COURSE_NAMES
    classes = ",".join("'" + c.replace("'", "\\'") + "'" for c in names)
    with open(path, "w", encoding="utf-8") as f:
        f.write("@RELATION ONET_RIASEC_Top3_Binary\n\n" if fmt == "binary" else "@RELATION student_riasec\n\n")
        for d in DIMENSIONS:
            f.write(f"@ATTRIBUTE {d} {{x,blank}}\n" if fmt == "top3" else f"@ATTRIBUTE {d} NUMERIC\n")
        class_name = "'Actual Course'" if fmt == "binary" else "ActualCourse"
        f.write(f"@ATTRIBUTE {class_name} {{{classes}}}\n\n@DATA\n")
        for r in rows:
            if fmt == "top3":
                vec = student_vector(r, "top3")
                cells = ["x" if v else "blank" for v in vec]
            elif fmt == "binary":
                cells = [str(int(v)) for v in student_vector(r, "top3")]
            else:
                cells = [f"{v:g}" for v in student_vector(r, scale)]
            f.write(",".join(cells) + ",'" + r["ActualCourse"].replace("'", "\\'") + "'\n")


# ---------------------------------------------------------------- commands

def cmd_courses(a):
    rows = [[col, name, orig, final, *[f"{v:.2f}" for v in holland_to_vector(final)]] for col, name, orig, final in COURSES]
    write_csv(a.out, ["College", "Course", "OriginalHollandCode", "FinalHollandCode", *DIMENSIONS], rows)
    print(f"Wrote {a.out} ({len(rows)} courses, {DEFAULT_ENCODING} encoding {ENCODINGS[DEFAULT_ENCODING]} on FinalHollandCode)")


def cmd_arff(a):
    """Every student as the deployed model's training file (top-3 binary, numeric 0/1)."""
    rows = read_students(a.students)
    write_arff(a.out, rows, "top3", "binary")
    ties = sum(1 for r in rows if not is_marked_row(r) and third_place_tie(r))
    print(f"Wrote {a.out}: {len(rows)} students, {len({r['ActualCourse'] for r in rows})} courses, top-3 binary (0/1)")
    if ties:
        print(f"  {ties} student(s) tie for 3rd place; the 1 goes to the earlier letter in R,I,A,S,E,C order (the app's rule)")


def cmd_split(a):
    """Stratified split by ActualCourse. Classes with a single student go to train
    (they cannot be tested) and are reported."""
    rows = read_students(a.students)
    rng = random.Random(a.seed)
    by_class = {}
    for r in rows:
        by_class.setdefault(r["ActualCourse"], []).append(r)
    train, test, singletons = [], [], []
    for course, group in sorted(by_class.items()):
        rng.shuffle(group)
        if len(group) < 2:
            train += group
            singletons.append(course)
            continue
        n_test = max(1, round(len(group) * a.test_fraction))
        test += group[:n_test]
        train += group[n_test:]
    header = ["StudentID", *DIMENSIONS, "ActualCourse"]
    base = os.path.dirname(os.path.abspath(a.students))
    for name, part in (("train", train), ("test", test)):
        write_csv(os.path.join(base, f"{name}.csv"), header, [[r[h] for h in header] for r in part])
        write_arff(os.path.join(base, f"{name}.arff"), part, a.scale, getattr(a, "arff", "top3"))
    write_arff(os.path.join(base, "all.arff"), rows, a.scale, getattr(a, "arff", "top3"))
    print(f"train={len(train)} test={len(test)} (seed {a.seed}); files in {base}")
    print(f"  all.arff = every student (for 10-fold cross-validation / the final tree for the web app)")
    counts = Counter(r["ActualCourse"] for r in rows)
    small = sorted(c for c, n in counts.items() if n < 5)
    missing = [c for c in COURSE_NAMES if c not in counts]
    print(f"  {len(counts)} courses in the data; largest: {counts.most_common(1)[0][0]} ({counts.most_common(1)[0][1]})")
    if small:
        print(f"  Courses with fewer than 5 students (hard to learn): {small}")
    if missing:
        print(f"  Courses with no students (can never be predicted by J48): {missing}")
    ties = sum(1 for r in rows if not is_marked_row(r) and third_place_tie(r))
    if ties:
        print(f"  {ties} student(s) tie for 3rd place; the x goes to the earlier letter in R,I,A,S,E,C order")
    if singletons:
        print(f"Courses with only 1 student (train only, not testable): {singletons}")


def cmd_cbf(a):
    rows = read_students(a.students)
    cvecs = course_vectors(ENCODINGS[a.encoding])
    out = []
    for r in rows:
        for pos, (name, s, rank) in enumerate(rank_courses(student_vector(r, a.scale), cvecs), start=1):
            out.append([r["StudentID"], name, f"{s:.6f}", rank, int(pos <= a.k), r["ActualCourse"]])
    write_csv(a.out, ["StudentID", "Course", "CosineSimilarity", "Rank", "Recommended", "ActualCourse"], out)
    m = evaluate(rows, cvecs, a.k, a.scale)
    print(f"Wrote {a.out} ({len(out)} rows = {len(rows)} students x 32 courses)")
    print(f"Encoding={a.encoding} {ENCODINGS[a.encoding]}  scale={a.scale}")
    for key, v in m.items():
        if not key.startswith("_"):
            print(f"  {key:24} {v:.4f}" if isinstance(v, float) else f"  {key:24} {v}")


def cmd_weights(a):
    rows = read_students(a.students)
    results = {name: evaluate(rows, course_vectors(w), a.k, a.scale) for name, w in ENCODINGS.items()}
    print(f"n={len(rows)} students, K={a.k}, scale={a.scale}  (tie-aware, expected values)")
    print(f"{'encoding':10} {'weights':18} {'HitRate@K':>10} {'MRR':>8} {'Top1':>8}   MRR diff vs proposed [95% CI]")
    base = results["proposed"]["_per_student_rr"]
    for name, m in results.items():
        lo, hi = paired_bootstrap(m["_per_student_rr"], base) if name != "proposed" else (0.0, 0.0)
        diff = m["MRR"] - results["proposed"]["MRR"]
        print(f"{name:10} {str(ENCODINGS[name]):18} {m[f'HitRate@{a.k}']:10.4f} {m['MRR']:8.4f} "
              f"{m['Top1Accuracy(expected)']:8.4f}   {diff:+.4f} [{lo:+.4f}, {hi:+.4f}]")


def rank_metrics(scores, actual, k):
    """Tie-aware Hit@1, Hit@K and reciprocal rank of `actual` in a {course: score} dict."""
    s = scores.get(actual, 0.0)
    better = sum(1 for o in scores.values() if o > s + TIE_EPS)
    t = sum(1 for o in scores.values() if abs(o - s) <= TIE_EPS)
    hit = lambda kk: min(max(kk - better, 0), t) / t
    rr = sum(1 / p for p in range(better + 1, better + t + 1)) / t
    return hit(1), hit(k), rr


def read_weka_predictions(path):
    """Predictions from WEKA (Classify -> More options -> Output predictions -> CSV, optionally
    with "Output distribution"), saved with the result buffer. Returns, in test-set order,
    [(actual, predicted, {course: probability} or None)]."""
    with open(path, encoding="utf-8-sig") as f:
        lines = f.read().splitlines()
    start = next((i for i, l in enumerate(lines) if l.strip().lower().startswith("inst#,")), None)
    if start is None:
        raise SystemExit(f"{path}: no CSV predictions found. In WEKA choose More options -> "
                         "Output predictions -> CSV, run J48 again, then save the result buffer.")
    label = lambda cell: cell.split(":", 1)[1].strip().strip("'\"") if ":" in cell else cell.strip()
    out = []
    for line in lines[start + 1:]:
        if not line.strip() or not line.strip()[0].isdigit():
            break
        cells = next(csv.reader([line]))
        actual, predicted = label(cells[1]), label(cells[2])
        dist = None
        probs = [c.strip().lstrip("*") for c in cells[4:] if c.strip() != ""]
        if len(probs) == len(COURSE_NAMES):
            dist = {name: float(p) for name, p in zip(COURSE_NAMES, probs)}
        out.append((COURSE_LOOKUP.get(_key(actual), actual), COURSE_LOOKUP.get(_key(predicted), predicted), dist))
    return out


def mcnemar_exact(a_right, b_right):
    """Exact two-sided McNemar test on paired 0/1 outcomes. Returns (b, c, p)."""
    b = sum(1 for x, y in zip(a_right, b_right) if x and not y)
    c = sum(1 for x, y in zip(a_right, b_right) if y and not x)
    n = b + c
    if n == 0:
        return b, c, 1.0
    tail = sum(math.comb(n, i) for i in range(0, min(b, c) + 1)) / 2 ** n
    return b, c, min(1.0, 2 * tail)


def cmd_compare(a):
    """CBF vs J48 on the SAME test students (METHODOLOGY.md §9)."""
    train = read_students(a.train, quiet=True)
    test = read_students(a.students)
    preds = read_weka_predictions(a.weka)
    if len(preds) != len(test):
        raise SystemExit(f"{a.weka} has {len(preds)} predictions but {a.students} has {len(test)} students. "
                         "Run J48 with Supplied test set = the test.arff made together with this test.csv.")
    for i, (row, (actual, _, _)) in enumerate(zip(test, preds), start=1):
        if actual != row["ActualCourse"]:
            raise SystemExit(f"Row {i}: WEKA says {actual!r}, test.csv says {row['ActualCourse']!r}. "
                             "The predictions are not for this test set, in this order.")
    cvecs = course_vectors(ENCODINGS[DEFAULT_ENCODING])
    k = a.k
    has_scores = not any(is_marked_row(r) for r in test)
    cbf_scales = (["scores"] if has_scores else []) + ["top3"]
    majority = Counter(r["ActualCourse"] for r in train).most_common(1)[0][0]

    res, per = {}, {"J48": {"top1": [], "hitk": [], "rr": []}}
    for sc in cbf_scales:
        per[f"CBF ({sc})"] = {"top1": [], "hitk": [], "rr": []}
    rows_out = []
    have_dist = all(d is not None for _, _, d in preds)
    for row, (actual, predicted, dist) in zip(test, preds):
        out = [row["StudentID"], actual, predicted, int(predicted == actual)]
        per["J48"]["top1"].append(float(predicted == actual))
        if have_dist:
            _, hk, rr = rank_metrics(dist, actual, k)
            per["J48"]["hitk"].append(hk)
            per["J48"]["rr"].append(rr)
        for sc in cbf_scales:
            svec = student_vector(row, sc)
            scores = {name: cosine(svec, v) for name, v in cvecs.items()}
            h1, hk, rr = rank_metrics(scores, actual, k)
            m = per[f"CBF ({sc})"]
            m["top1"].append(h1); m["hitk"].append(hk); m["rr"].append(rr)
            best = max(scores.values())
            out += [" | ".join(n for n, v in scores.items() if abs(v - best) <= TIE_EPS), f"{h1:.4f}", f"{hk:.4f}"]
        rows_out.append(out)

    n = len(test)
    mean = lambda xs: sum(xs) / len(xs) if xs else float("nan")
    print(f"\nCBF vs J48 on the same {n} test students (K = {k}; CBF ties counted as expected values)\n")
    print(f"{'Method':28} {'Top-1':>8} {'Hit@' + str(k):>8} {'MRR':>8}")
    print(f"{'Random guess':28} {1 / len(COURSE_NAMES):8.4f} {k / len(COURSE_NAMES):8.4f} {'':>8}")
    print(f"{'Most frequent course':28} {mean([float(r['ActualCourse'] == majority) for r in test]):8.4f} {'':>8} {'':>8}")
    for name, m in per.items():
        hk = f"{mean(m['hitk']):8.4f}" if m["hitk"] else f"{'n/a':>8}"
        rr = f"{mean(m['rr']):8.4f}" if m["rr"] else f"{'n/a':>8}"
        print(f"{name:28} {mean(m['top1']):8.4f} {hk} {rr}")
    print(f"\n(Most frequent course in train.csv: {majority})")
    if not have_dist:
        print(f"\n(J48 Hit@{k}/MRR need WEKA's class probabilities: tick 'Output distribution' with the CSV predictions.)")

    main_cbf = f"CBF ({cbf_scales[0]})"
    lo, hi = paired_bootstrap(per["J48"]["top1"], per[main_cbf]["top1"])
    print(f"\nTop-1 difference J48 - {main_cbf}: {mean(per['J48']['top1']) - mean(per[main_cbf]['top1']):+.4f}  95% CI [{lo:+.4f}, {hi:+.4f}] (paired bootstrap)")
    if per["J48"]["rr"]:
        lo, hi = paired_bootstrap(per["J48"]["rr"], per[main_cbf]["rr"])
        print(f"MRR difference   J48 - {main_cbf}: {mean(per['J48']['rr']) - mean(per[main_cbf]['rr']):+.4f}  95% CI [{lo:+.4f}, {hi:+.4f}]")
    # McNemar needs a single CBF answer per student: only students whose top course is not tied count as CBF-correct.
    cbf_strict = [t == 1.0 for t in per[main_cbf]["top1"]]
    b, c, p = mcnemar_exact([x == 1.0 for x in per["J48"]["top1"]], cbf_strict)
    print(f"McNemar (exact), Top-1: J48 right & CBF wrong = {b}, CBF right & J48 wrong = {c}, p = {p:.4f}"
          "  (CBF counted right only when its top course is unique)")

    header = ["StudentID", "ActualCourse", "J48Predicted", "J48Correct"]
    for sc in cbf_scales:
        header += [f"CBF_{sc}_Top", f"CBF_{sc}_Top1", f"CBF_{sc}_Hit@{k}"]
    write_csv(a.out, header, rows_out)
    print(f"\nPer-student results: {a.out}")


# ---------------------------------------------------------------- WEKA model vs CBF agreement

_TRUE = {"x", "yes", "y", "1", "true", "t"}
_FALSE = {"no", "n", "0", "false", "f", "", "?", "blank", "none"}


def _rank_features(vals):
    order = sorted(range(6), key=lambda i: -vals[i])
    out, i = [0.0] * 6, 0
    while i < 6:
        j = i
        while j + 1 < 6 and abs(vals[order[j + 1]] - vals[order[i]]) < 1e-9:
            j += 1
        for k in range(i, j + 1):
            out[order[k]] = (i + j) / 2 + 1
        i = j + 1
    return out


def predict_tree(model, row):
    """Walk an imported J48 model (config/prediction_model.json) for one student, exactly as
    modules/ContentBasedFiltering/lib/PredictionModel.php does. Returns the class label, or None when no branch matches."""
    features = model.get("features", "top3")
    if features == "rank":
        vals = dict(zip(DIMENSIONS, _rank_features([float(row[d]) for d in DIMENSIONS])))
    else:
        vals = dict(zip(DIMENSIONS, student_vector(row, "top3")))  # 1 = in the top 3
    node = model["tree"]
    while "class" not in node:
        d = str(node.get("attribute", "")).strip(" '\"").upper()[:1]
        if d not in DIMENSIONS:
            return None
        x = vals[d]
        nxt = None
        for b in node.get("branches", []):
            op, v = b["op"], str(b["value"]).strip().lower()
            if op in ("=", "!=") and features != "rank":
                eq = (x == 1.0) if v in _TRUE else (x == 0.0) if v in _FALSE else None
                if eq is None:
                    continue
                ok = eq if op == "=" else not eq
            else:
                t = float(v)
                ok = {"<=": x <= t, "<": x < t, ">": x > t, ">=": x >= t, "=": abs(x - t) < 1e-9, "!=": abs(x - t) >= 1e-9}[op]
            if ok:
                nxt = b["node"]
                break
        if nxt is None:
            return None
        node = nxt
    return str(node["class"])


def cmd_agreement(a):
    """Per-student agreement between the imported WEKA model and the CBF Best RIASEC Match,
    next to how often the students' OWN course (the class) agrees with the CBF. The second
    number is the ceiling a model trained on that class can be expected to approach."""
    import json
    rows = read_students(a.students)
    with open(a.model, encoding="utf-8") as f:
        model = json.load(f)
    scale = "scores" if not any(is_marked_row(r) for r in rows) else "top3"
    cvecs = course_vectors(ENCODINGS[DEFAULT_ENCODING])
    code = {n: f for _, n, _, f in COURSES}
    per_combo = {}
    pred_in, own_in, pred_in_alt, no_pred = 0, 0, 0, 0
    for r in rows:
        scores = {c: cosine(student_vector(r, scale), v) for c, v in cvecs.items()}
        levels = sorted({round(v, 9) for v in scores.values()}, reverse=True)
        best = {c for c, v in scores.items() if round(v, 9) == levels[0]}
        alt = {c for c, v in scores.items() if len(levels) > 1 and round(v, 9) == levels[1]}
        label = predict_tree(model, r)
        pred = COURSE_LOOKUP.get(_key(label)) if label else None
        combo = "".join(d for d, v in zip(DIMENSIONS, student_vector(r, "top3")) if v)
        c = per_combo.setdefault(combo, {"n": 0, "pred": pred, "in": 0, "own": 0})
        c["n"] += 1
        if pred is None:
            no_pred += 1
        else:
            pred_in += pred in best
            pred_in_alt += pred in best | alt
            c["in"] += pred in best
        own_in += r["ActualCourse"] in best
        c["own"] += r["ActualCourse"] in best
    n = len(rows)
    print(f"\nStudents: {n}  (CBF student vector: {'actual scores, as the web app' if scale == 'scores' else 'top-3 x/blank'};"
          f" model: {model.get('version', '?')}, features {model.get('features', 'top3')})\n")
    print(f"{'Top 3':6} {'Students':>8}  {'WEKA predicts':32} {'code':5} {'WEKA in Best Match':>18} {'Own course in Best Match':>25}")
    for combo, c in sorted(per_combo.items(), key=lambda kv: -kv[1]["n"]):
        print(f"{combo:6} {c['n']:8}  {str(c['pred']):32} {code.get(c['pred'], '-'):5} {c['in']:>11}/{c['n']:<6} {c['own']:>18}/{c['n']}")
    print(f"\nWEKA's course is a Best RIASEC Match for        {pred_in}/{n} students ({pred_in / n:.1%})")
    print(f"WEKA's course is a Best Match or Alternative for {pred_in_alt}/{n} students ({pred_in_alt / n:.1%})")
    print(f"Students' OWN course is a Best RIASEC Match for  {own_in}/{n} students ({own_in / n:.1%})  <- ceiling for a model trained on this class")
    if no_pred:
        print(f"No prediction (no tree branch): {no_pred} students")
    print(f"Distinct top-3 patterns in the data: {len(per_combo)} of 20; the earlier '7 of 20' counted each pattern once, regardless of how many students have it.")


def main():
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = p.add_subparsers(dest="cmd", required=True)
    c = sub.add_parser("courses")
    c.add_argument("--out", default="course_profiles.csv")
    for name in ("split", "cbf", "weights"):
        s = sub.add_parser(name)
        s.add_argument("--students", required=True)
        s.add_argument("--scale", choices=["auto", "top3", "scores", "unit", "raw50", "minmax"], default="auto")
        if name == "split":
            s.add_argument("--test-fraction", type=float, default=0.3)
            s.add_argument("--seed", type=int, default=42)
            s.add_argument("--arff", choices=["top3", "numeric"], default="top3",
                           help="WEKA inputs: top 3 types as x/blank (default, what the web app runs) or numeric")
        else:
            s.add_argument("--k", type=int, default=3)
        if name == "cbf":
            s.add_argument("--encoding", choices=list(ENCODINGS), default=DEFAULT_ENCODING)
            s.add_argument("--out", default="cbf_results.csv")
    w = sub.add_parser("arff", help="training file for the deployed Prediction Module model (top-3 binary)")
    w.add_argument("--students", required=True)
    w.add_argument("--out", default="all_binary.arff")
    g = sub.add_parser("agreement")
    g.add_argument("--students", required=True, help="student CSV with the course column (all students)")
    g.add_argument("--model", default=os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "config", "prediction_model.json"))
    c = sub.add_parser("compare")
    c.add_argument("--train", required=True, help="train.csv from `split` (for the most-frequent baseline)")
    c.add_argument("--students", required=True, help="test.csv from `split`")
    c.add_argument("--weka", required=True, help="WEKA result buffer with CSV predictions on test.arff")
    c.add_argument("--k", type=int, default=3)
    c.add_argument("--out", default="comparison_results.csv")
    a = p.parse_args()
    {"courses": cmd_courses, "split": cmd_split, "cbf": cmd_cbf, "weights": cmd_weights, "compare": cmd_compare, "agreement": cmd_agreement, "arff": cmd_arff}[a.cmd](a)


if __name__ == "__main__":
    main()
