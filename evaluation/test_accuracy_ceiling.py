"""Tests for accuracy_ceiling.py. Run: python3 -m unittest evaluation/test_accuracy_ceiling.py
All student rows here are synthetic fixtures for checking the arithmetic, not study data."""
import os
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.dirname(__file__))
import accuracy_ceiling as m  # noqa: E402

SIR = (1, 1, 0, 1, 0, 0)
RIC = (1, 1, 0, 0, 0, 1)


def write(text, suffix):
    f = tempfile.NamedTemporaryFile("w", suffix=suffix, delete=False, encoding="utf-8")
    f.write(text)
    f.close()
    return f.name


class Ceilings(unittest.TestCase):
    def test_hand_computed_ceiling(self):
        # SIR: 3 Nursing, 1 Biology; RIC: 2 IT, 2 CS (tie). Best possible: 3 + 2 = 5 of 8.
        pairs = [(SIR, "BS Nursing")] * 3 + [(SIR, "BS Biology")] + [(RIC, "BS Information Technology")] * 2 \
            + [(RIC, "BS Computer Science")] * 2
        exact, top3, detail = m.ceilings(pairs)
        self.assertAlmostEqual(exact, 5 / 8)
        self.assertAlmostEqual(top3, 1.0)   # at most 2 courses per pattern, so all fit in a top 3
        self.assertEqual(detail[0][1:5], (4, 2, "BS Nursing", 3))

    def test_one_pattern_many_courses_caps_accuracy(self):
        pairs = [(SIR, c) for c in ["BS Nursing", "BS Biology", "BS Pharmacy", "BS Physical Therapy", "BS Psychology"]]
        self.assertAlmostEqual(m.ceilings(pairs)[0], 1 / 5)
        self.assertAlmostEqual(m.ceilings(pairs)[1], 3 / 5)

    def test_cross_validation_never_beats_the_ceiling_and_repeats(self):
        pairs = ([(SIR, "BS Nursing")] * 12 + [(SIR, "BS Biology")] * 5 + [(RIC, "BS Information Technology")] * 9
                 + [(RIC, "BS Computer Science")] * 6 + [((0, 0, 1, 1, 1, 0), "BA Communication")] * 8)
        exact, top3, _ = m.ceilings(pairs)
        first = m.cross_validate(pairs)
        self.assertEqual(first, m.cross_validate(pairs))   # same seeds, same result
        (cv, _), (cv3, _), (zr, _) = first
        self.assertLessEqual(cv, exact + 1e-12)
        self.assertLessEqual(cv3, top3 + 1e-12)
        self.assertGreater(cv, zr)   # patterns carry information here, so the rule beats ZeroR

    def test_stratified_folds_cover_every_row_once(self):
        labels = ["a"] * 7 + ["b"] * 13
        folds = m.stratified_folds(labels, 10, 3)
        self.assertEqual(sorted(i for f in folds for i in f), list(range(20)))
        self.assertTrue(all(len(f) == 2 for f in folds))


class Reading(unittest.TestCase):
    def test_arff_with_quoted_class_and_misspelled_course(self):
        path = write("@relation synthetic\n@attribute R numeric\n@attribute I numeric\n@attribute A numeric\n"
                     "@attribute S numeric\n@attribute E numeric\n@attribute C numeric\n"
                     "@attribute 'Actual Course' {'BS Pyschology','BS Nursing'}\n@data\n"
                     "1,1,0,1,0,0,'BS Pyschology'\n0,1,1,1,0,0,'BS Nursing'\n1,1,?,1,0,0,'BS Nursing'\n", ".arff")
        try:
            pairs, skipped, not_three = m.load(path)
        finally:
            os.unlink(path)
        self.assertEqual(pairs, [(SIR, "BS Psychology"), ((0, 1, 1, 1, 0, 0), "BS Nursing")])
        self.assertEqual((skipped, not_three), (1, 0))

    def test_csv_scores_become_top3_and_undecided_is_left_out(self):
        path = write("StudentID,R,I,A,S,E,C,course\nS1,30,40,10,45,20,20,BSN\nS2,20,20,20,20,20,20,BSIT\n"
                     "S3,30,30,30,30,30,30,Undecided\n", ".csv")
        try:
            pairs, _, _ = m.load(path)
        finally:
            os.unlink(path)
        # S2 is flat: ties keep R, I, A order, the app's rule.
        self.assertEqual(pairs, [(SIR, "BS Nursing"), ((1, 1, 1, 0, 0, 0), "BS Information Technology")])

    def test_every_course_has_a_college(self):
        colleges = m.college_map(os.path.join(os.path.dirname(__file__), "course_profiles.csv"))
        self.assertEqual(len(colleges), 32)
        self.assertEqual(colleges[m.course_name("BS Hospitality Managment")], colleges["BS Hospitality Management"])


if __name__ == "__main__":
    unittest.main()
