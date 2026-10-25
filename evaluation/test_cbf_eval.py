"""Tests for cbf_eval.py. Run: python3 -m unittest evaluation/test_cbf_eval.py
All student rows here are synthetic fixtures for checking the algorithm, not study data."""
import csv
import math
import os
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.dirname(__file__))
import cbf_eval as m  # noqa: E402


def student(sid, vec, actual="BS Architecture"):
    return {"StudentID": sid, **dict(zip(m.DIMENSIONS, map(str, vec))), "ActualCourse": actual}


class Vectors(unittest.TestCase):
    def test_default_is_binary_like_the_web_app(self):
        self.assertEqual(m.DEFAULT_ENCODING, "binary")
        self.assertEqual(m.holland_to_vector("IRC"), [1.0, 1.0, 0.0, 0.0, 0.0, 1.0])
        self.assertEqual(m.holland_to_vector("RIC"), m.holland_to_vector("ICR"))   # order not used
        self.assertEqual(m.holland_to_vector("AES"), [0.0, 0.0, 1.0, 1.0, 1.0, 0.0])

    def test_ranksum_is_kept_only_as_a_comparison(self):
        r = m.ENCODINGS["ranksum"]
        self.assertEqual(m.holland_to_vector("IRC", r), [2.0, 3.0, 0.0, 0.0, 0.0, 1.0])
        self.assertEqual(m.holland_to_vector("RIC", r), [3.0, 2.0, 0.0, 0.0, 0.0, 1.0])

    def test_proposed_encoding_examples(self):
        p = m.ENCODINGS["proposed"]
        self.assertEqual(m.holland_to_vector("IRC", p), [0.67, 1.00, 0.0, 0.0, 0.0, 0.33])
        self.assertEqual(m.holland_to_vector("RIC", p), [1.00, 0.67, 0.0, 0.0, 0.0, 0.33])
        self.assertEqual(m.holland_to_vector("AES", p), [0.0, 0.0, 1.00, 0.33, 0.67, 0.0])

    def test_invalid_codes_rejected(self):
        for bad in ("RI", "RRI", "RIX", "RIAS"):
            with self.assertRaises(ValueError):
                m.holland_to_vector(bad)

    def test_all_32_final_codes_valid_and_vectors_in_range(self):
        self.assertEqual(len(m.COURSES), 32)
        for vec in m.course_vectors(m.ENCODINGS["proposed"]).values():
            self.assertEqual(sorted(vec, reverse=True)[:3], [1.0, 0.67, 0.33])

    def test_binary_encoding_loses_letter_order(self):
        v = m.course_vectors(m.ENCODINGS["binary"])
        self.assertEqual(v["BS Computer Science"], v["BS Civil Engineering"])     # IRC vs RIC
        self.assertEqual(v["BS Computer Engineering"], v["BS Civil Engineering"])  # ICR vs RIC
        p = m.course_vectors(m.ENCODINGS["proposed"])
        self.assertNotEqual(p["BS Computer Science"], p["BS Civil Engineering"])


class Cosine(unittest.TestCase):
    def test_identical_orthogonal_opposite_zero(self):
        a = [0.67, 1, 0, 0, 0, 0.33]
        self.assertAlmostEqual(m.cosine(a, a), 1.0)
        self.assertAlmostEqual(m.cosine(a, [x * 7 for x in a]), 1.0)
        self.assertEqual(m.cosine([1, 0, 0, 0, 0, 0], [0, 1, 0, 0, 0, 0]), 0.0)
        self.assertAlmostEqual(m.cosine([1, 2, 3], [-1, -2, -3]), -1.0)
        self.assertEqual(m.cosine([0] * 6, a), 0.0)
        self.assertEqual(m.cosine([0] * 6, [0] * 6), 0.0)

    def test_hand_computed(self):
        s = [0.72, 0.91, 0.40, 0.35, 0.60, 0.78]
        c = [0.67, 1.00, 0, 0, 0, 0.33]
        expected = (0.72 * 0.67 + 0.91 + 0.78 * 0.33) / (math.sqrt(sum(x * x for x in s)) * math.sqrt(0.67**2 + 1 + 0.33**2))
        self.assertAlmostEqual(m.cosine(s, c), expected)


class Ranking(unittest.TestCase):
    def setUp(self):
        self.cv = m.course_vectors(m.ENCODINGS["proposed"])

    def test_known_best_course(self):
        ranked = m.rank_courses(m.holland_to_vector("ARI", m.ENCODINGS["proposed"]), self.cv)
        self.assertEqual(ranked[0][:1], ("BS Architecture",))
        self.assertAlmostEqual(ranked[0][1], 1.0)

    def test_all_32_ranked_in_descending_order(self):
        ranked = m.rank_courses([0.1, 0.9, 0.3, 0.2, 0.6, 0.4], self.cv)
        self.assertEqual(len(ranked), 32)
        scores = [s for _, s, _ in ranked]
        self.assertEqual(scores, sorted(scores, reverse=True))
        self.assertEqual([r for _, _, r in ranked], sorted(r for _, _, r in ranked))

    def test_profile_with_no_exact_course_code_still_ranks_everything(self):
        # Student's top-3 letters are A, I, S ("AIS") - no course has that code.
        ranked = m.rank_courses([0.2, 0.8, 0.9, 0.7, 0.1, 0.1], self.cv)
        self.assertEqual(len(ranked), 32)
        self.assertLess(ranked[0][1], 1.0)

    def test_identical_course_vectors_share_rank_and_tie_metrics(self):
        ranked = m.rank_courses(m.holland_to_vector("IRC", m.ENCODINGS["proposed"]), self.cv)
        irc = [n for n, _, r in ranked if r == 1]
        self.assertEqual(sorted(irc), sorted(["BS Computer Science", "BS Information Technology",
                                              "BS Medical Technology", "BS Chemical Engineering"]))
        hit1, rr, best_rank, ties = m.tie_aware(ranked, "BS Information Technology", 1)
        self.assertEqual((best_rank, ties), (1, 4))
        self.assertAlmostEqual(hit1, 0.25)
        self.assertAlmostEqual(rr, (1 + 1 / 2 + 1 / 3 + 1 / 4) / 4)
        self.assertAlmostEqual(m.tie_aware(ranked, "BS Information Technology", 3)[0], 0.75)
        self.assertAlmostEqual(m.tie_aware(ranked, "BS Information Technology", 4)[0], 1.0)

    def test_binary_encoding_ties_all_courses_with_same_letters(self):
        # IRC x4, RIC x6 and ICR x1 share the letters I, R, C -> 11 courses tie.
        binary = m.course_vectors(m.ENCODINGS["binary"])
        ranked = m.rank_courses([0.72, 0.91, 0.40, 0.35, 0.60, 0.78], binary)
        self.assertEqual(sum(1 for _, _, r in ranked if r == 1), 11)
        self.assertAlmostEqual(ranked[0][1], 0.8634, places=4)

    def test_zero_vector_student_does_not_crash(self):
        ranked = m.rank_courses([0] * 6, self.cv)
        self.assertTrue(all(s == 0.0 and r == 1 for _, s, r in ranked))

    def test_actual_course_cannot_influence_ranking(self):
        a = student("X", [0.3, 0.4, 0.9, 0.5, 0.7, 0.2], "BS Nursing")
        b = dict(a, ActualCourse="BS Marketing")
        self.assertEqual(m.rank_courses(m.student_vector(a), self.cv), m.rank_courses(m.student_vector(b), self.cv))


class Scaling(unittest.TestCase):
    def test_raw50_preserves_cosine_minmax_changes_it(self):
        raw = student("X", [30, 45, 20, 18, 25, 40])
        cv = m.holland_to_vector("IRC")
        unit = m.cosine([float(raw[d]) for d in m.DIMENSIONS], cv)
        self.assertAlmostEqual(m.cosine(m.student_vector(raw, "raw50"), cv), unit)
        self.assertNotAlmostEqual(m.cosine(m.student_vector(raw, "minmax"), cv), unit)

    def test_out_of_range_rejected(self):
        with self.assertRaises(ValueError):
            m.student_vector(student("X", [1.2, 0, 0, 0, 0, 0]), "unit")
        with self.assertRaises(ValueError):
            m.student_vector(student("X", [5, 20, 20, 20, 20, 20]), "raw50")


class AdviserCsvFormat(unittest.TestCase):
    """The adviser's sample: blank first header, x under the student's letters, course 'bsit'."""

    def read(self, text):
        with tempfile.TemporaryDirectory() as d:
            path = os.path.join(d, "students.csv")
            with open(path, "w", newline="", encoding="utf-8") as f:
                f.write(text)
            return m.read_students(path)

    def test_sample_row_with_only_c_i_e_columns(self):
        rows = self.read(",c,i,e,course\nstudent1,x,x,x,bsit\n")
        self.assertEqual(rows[0]["StudentID"], "student1")
        self.assertEqual(rows[0]["ActualCourse"], "BS Information Technology")
        self.assertEqual(m.student_vector(rows[0]), [0, 1, 0, 0, 1, 1])

    def test_all_six_columns_x_and_blank(self):
        rows = self.read("StudentID,R,I,A,S,E,C,course\nS1,x,,x,,,x,BS Architecture\n")
        self.assertEqual(m.student_vector(rows[0]), [1, 0, 1, 0, 0, 1])

    def test_numeric_scores_become_top3_like_the_app(self):
        rows = self.read("StudentID,R,I,A,S,E,C,course\nS1,36,39,37,32,39,41,BSA\n")
        self.assertEqual(m.student_vector(rows[0]), [0, 1, 0, 0, 1, 1])  # C, I, E
        self.assertEqual(rows[0]["ActualCourse"], "BS Accountancy")

    def test_unknown_course_is_reported(self):
        with self.assertRaises(SystemExit):
            self.read("StudentID,R,I,A,S,E,C,course\nS1,x,x,x,,,,BSMT\n")  # ambiguous, not accepted

    def test_x_student_vs_matching_course_scores_1(self):
        cv = m.course_vectors(m.ENCODINGS["binary"])
        ranked = m.rank_courses([0, 1, 0, 0, 1, 1], cv)  # C, I, E
        top = [n for n, s, r in ranked if r == 1]
        self.assertIn("BS Accountancy", top)            # CEI: 3 of 3 letters
        self.assertAlmostEqual(ranked[0][1], 1.0)


class Evaluation(unittest.TestCase):
    def test_metrics_relationships(self):
        cv = m.course_vectors(m.ENCODINGS["proposed"])
        rows = [student("1", m.holland_to_vector("ARI", m.ENCODINGS["proposed"]), "BS Architecture"),   # rank 1
                student("2", m.holland_to_vector("ARI", m.ENCODINGS["proposed"]), "BS Nursing")]        # far down
        res = m.evaluate(rows, cv, 3, "unit")
        self.assertAlmostEqual(res["HitRate@3"], 0.5)
        self.assertAlmostEqual(res["Recall@3"], res["HitRate@3"])
        self.assertAlmostEqual(res["Precision@3"], 0.5 / 3)
        self.assertGreater(res["MRR"], 0.5)

    def test_split_and_arff(self):
        with tempfile.TemporaryDirectory() as d:
            path = os.path.join(d, "students.csv")
            rows = [student(f"S{i}", [0.5] * 6, c) for i, c in
                    enumerate(["BS Nursing"] * 4 + ["BS Marketing"] * 3 + ["BS Biology"])]
            with open(path, "w", newline="", encoding="utf-8") as f:
                w = csv.DictWriter(f, ["StudentID", *m.DIMENSIONS, "ActualCourse"])
                w.writeheader()
                w.writerows(rows)

            class A: students, scale, test_fraction, seed = path, "unit", 0.3, 1
            m.cmd_split(A)
            def ids(name):
                with open(os.path.join(d, name), newline="", encoding="utf-8") as f:
                    return {r["StudentID"] for r in csv.DictReader(f)}
            test_ids, train_ids = ids("test.csv"), ids("train.csv")
            self.assertFalse(test_ids & train_ids)                 # no student in both
            self.assertEqual(len(test_ids | train_ids), 8)
            self.assertIn("S7", train_ids)                         # single-student class stays in train
            with open(os.path.join(d, "train.arff"), encoding="utf-8") as f:
                arff = f.read()
            self.assertNotIn("StudentID", arff)                    # ID is never a WEKA attribute
            self.assertEqual(arff.count("@ATTRIBUTE"), 7)          # R..C + class only

    def test_undecided_rows_are_removed_and_student_id_header_accepted(self):
        with tempfile.TemporaryDirectory() as d:
            path = os.path.join(d, "raw.csv")
            with open(path, "w", newline="", encoding="utf-8") as f:
                w = csv.writer(f)
                w.writerow(["student_id", "R", "I", "A", "S", "E", "C", "course"])
                w.writerow(["1", 2, 9, 1, 8, 3, 7, "BS Nursing"])
                w.writerow(["2", 5, 5, 5, 5, 5, 5, "Undecided"])
            rows = m.read_students(path, quiet=True)
            self.assertEqual([r["StudentID"] for r in rows], ["1"])

    def test_scores_scale_keeps_the_actual_profile(self):
        row = {"StudentID": "1", "R": "4", "I": "5", "A": "1", "S": "1", "E": "2", "C": "4"}
        self.assertEqual(m.student_vector(row, "scores"), [4.0, 5.0, 1.0, 1.0, 2.0, 4.0])
        # Same ranking as the web app: IRC course cosine 0.946 (13 / (sqrt(63) * sqrt(3))).
        self.assertAlmostEqual(m.cosine(m.student_vector(row, "scores"), m.holland_to_vector("IRC", m.ENCODINGS["binary"])), 13 / (math.sqrt(63) * math.sqrt(3)))

    def test_top3_arff_uses_x_blank(self):
        with tempfile.TemporaryDirectory() as d:
            path = os.path.join(d, "t.arff")
            m.write_arff(path, [{"StudentID": "1", "R": "2", "I": "9", "A": "1", "S": "8", "E": "3", "C": "7",
                                 "ActualCourse": "BS Nursing"}], "auto")
            with open(path, encoding="utf-8") as f:
                arff = f.read()
            self.assertIn("@ATTRIBUTE R {x,blank}", arff)
            self.assertIn("blank,x,blank,x,blank,x,'BS Nursing'", arff)   # top 3 = I, S, C

    def test_third_place_tie_detected(self):
        self.assertTrue(m.third_place_tie({"R": 9, "I": 8, "A": 7, "S": 7, "E": 1, "C": 0}))
        self.assertFalse(m.third_place_tie({"R": 9, "I": 8, "A": 7, "S": 6, "E": 1, "C": 0}))

    def test_weka_csv_predictions_are_read(self):
        with tempfile.TemporaryDirectory() as d:
            path = os.path.join(d, "out.txt")
            dist = ["0"] * len(m.COURSE_NAMES)
            dist[m.COURSE_NAMES.index("BS Nursing")] = "*0.75"
            dist[m.COURSE_NAMES.index("BS Biology")] = "0.25"
            with open(path, "w", encoding="utf-8") as f:
                f.write("=== Predictions on test data ===\n\ninst#,actual,predicted,error,distribution" + "," * 31 + "\n")
                f.write("1,10:'BS Nursing',10:BS Nursing,," + ",".join(dist) + "\n")
                f.write("2,2:'B Multimedia Arts',10:BS Nursing,+," + ",".join(dist) + "\n\n=== Evaluation ===\n")
            preds = m.read_weka_predictions(path)
            self.assertEqual([(a, p) for a, p, _ in preds], [("BS Nursing", "BS Nursing"), ("B Multimedia Arts", "BS Nursing")])
            self.assertAlmostEqual(preds[0][2]["BS Nursing"], 0.75)
            hit1, hit3, rr = m.rank_metrics(preds[0][2], "BS Biology", 3)
            self.assertEqual((hit1, rr), (0.0, 0.5))     # Biology is ranked 2nd by probability
            self.assertEqual(hit3, 1.0)

    def test_mcnemar_exact(self):
        b, c, p = m.mcnemar_exact([1, 1, 1, 0], [0, 0, 0, 0])
        self.assertEqual((b, c), (3, 0))
        self.assertAlmostEqual(p, 0.25)                 # 2 * (1/2)^3
        self.assertEqual(m.mcnemar_exact([1, 0], [1, 0])[2], 1.0)



class BinaryArff(unittest.TestCase):
    def test_deployed_format_from_an_anonymised_file(self):
        with tempfile.TemporaryDirectory() as d:
            src = os.path.join(d, "s.csv")
            with open(src, "w", newline="") as f:   # synthetic rows; no student column
                f.write("R,I,A,S,E,C,Actual Course\n3,2,6,1,19,8,BSBAOM\n10,10,9,9,2,1,BSN\n")
            rows = m.read_students(src)
            out = os.path.join(d, "a.arff")
            m.write_arff(out, rows, "top3", "binary")
            text = open(out).read()
        self.assertEqual([r["StudentID"] for r in rows], ["row1", "row2"])
        self.assertIn("@ATTRIBUTE R NUMERIC", text)
        self.assertIn("@ATTRIBUTE 'Actual Course' {'BS Nursing','BSBA Major in Operations Management'}", text)
        self.assertIn("0,0,1,0,1,1,'BSBA Major in Operations Management'", text)   # E, C, A
        self.assertIn("1,1,1,0,0,0,'BS Nursing'", text)                           # A ties S for 3rd: A first

if __name__ == "__main__":
    unittest.main()
