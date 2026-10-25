# ProfilePath: methodology notes for the system and the manuscript

ProfilePath supports career exploration and counselor guidance for MMCL Senior High School
students. It does not decide admission eligibility and does not predict academic success.

Objectives this document serves:

1. Centralize student profiles, RIASEC assessments, worksheets and counseling records.
2. Recommend MMCL programs with content-based filtering (CBF, cosine similarity), compare them with
   the student's Preferred Course, and show the careers associated with each program.
3. Protect sensitive information with the existing encryption (Modified AES-256) and access controls.

---

## 1. Assessment scoring

Production uses the 30-question O\*NET Mini Interest Profiler item set, 5 questions per RIASEC type
(`modules/CareerProfiling/lib/QuestionBank.php`). Staff can change the question bank; the assessment opens only while every
type has the same number of active questions (at least 3).

Keep these values distinct:

| Value | What it is | Range (5 per type) | Where |
|---|---|---|---|
| Item response | One answer: 1 Dislike, 2 Slightly Dislike, 3 Neutral, 4 Slightly Enjoy, 5 Enjoy | 1–5 | sent by `modules/CareerProfiling/pages/riasec-assessment.html`, stored per question in `assessments.answers_enc` |
| Raw total | Sum of a type's responses | 5–25 (n to 5n for n questions) | computed in `AssessmentVersion::score()` |
| Stored score | `QuestionBank::scaleScore`: 10 × raw ÷ n, rounded half up | 10–50 | `assessments.score_r … score_c` |
| Student vector | Stored score ÷ 10 (mean response) | 1.0–5.0 | `CBFEngine::studentVector()` |
| O\*NET Mini-IP scores (study dataset) | The same 30 items answered on O\*NET's own Mini Interest Profiler: each answer 0–4 (Strongly Dislike … Strongly Like), summed per type | 0–20 | the 393-record training dataset; see §1.1 |

- **5 per type:** stored score = raw × 2 exactly.
- **10 per type:** stored = raw.
- **Other counts** are rounded half up, e.g. 4 per type: raw 5 → 12.5 → 13; 3 per type: raw 4 → 13.33 → 13.
- **Defect fixed (2026-10-23):** the earlier floating-point version rounded 14.5 and 28.5 down for 20 questions per type. Every other count from 3 to 20 gave the same results, and the 5-per-type production scoring is unchanged.

### 1.1 The study dataset and ProfilePath use the same questionnaire

The 393 training records were collected with O\*NET's **Mini Interest Profiler (Mini-IP)**, 30 items,
5 per type (confirmed by the team, 2026-10-24). ProfilePath's assessment uses the same 30 items
(`QuestionBank::ONET_MINI_IP`, from the Mini-IP development report). Only the answer coding differs:

| Answer position | O\*NET Mini-IP label | O\*NET value | ProfilePath label | ProfilePath value |
|---|---|---|---|---|
| 1 | Strongly Dislike | 0 | Dislike | 1 |
| 2 | Dislike | 1 | Slightly Dislike | 2 |
| 3 | Unsure | 2 | Neutral | 3 |
| 4 | Like | 3 | Slightly Enjoy | 4 |
| 5 | Strongly Like | 4 | Enjoy | 5 |

Per type (5 items): O\*NET score = sum of the five 0–4 answers (0–20). ProfilePath raw total = O\*NET
score + 5 (5–25), and the stored score = 2 × raw = **2 × O\*NET score + 10** (10–50).

- **Same ranking.** The conversion is a straight increasing line, so the order of the six types,
  and any ties, are the same on both scales. The top three, and so the Prediction Module's 0/1
  inputs, are identical whichever scale is used.
- **Worked example** (a record from the dataset): O\*NET R 3, I 2, A 6, S 1, E 19, C 8 → ProfilePath
  stored R 16, I 14, A 22, S 12, E 48, C 26. Both rank E, C, A, R, I, S, so the top three are E, C, A
  and the inputs are R 0, I 0, A 1, S 0, E 1, C 1.
- **Cosine values differ.** The CBF uses ProfilePath's stored scores ÷ 10 (= 0.2 × O\*NET + 1), which
  is not proportional to the O\*NET score, so a cosine computed from raw O\*NET scores would differ.
  The CBF always uses the application's own scores; no O\*NET scores enter it.
- **Remaining difference:** ProfilePath's answer labels are reworded (e.g. "Neutral" for "Unsure",
  "Slightly Enjoy" for "Like"). The five positions are the same, but students may read the labels
  slightly differently (minor limitation).
- **Where the scoring is documented:** the Mini-IP development report and the O\*NET Interest
  Profiler Manual (§9). The conversion is documented here; no stored records are converted.

## 2. Reproducible assessments

`modules/CareerProfiling/lib/AssessmentVersion.php`, migration `db/migrate_add_assessment_versions.php`.

- **Question sets.** A question set is an immutable snapshot of the active questions shown: id, type, exact wording and order. Its id is tied to the SHA-256 fingerprint of that snapshot, so editing, adding, hiding or reordering a question creates a new set; an existing set never changes.
- **Snapshot storage.** The snapshot is stored encrypted (`assessment_question_sets.snapshot_enc`).
- **What the student receives.** `modules/CareerProfiling/api/assessment-questions.php` gives the student the set id. Saved progress (`modules/CareerProfiling/lib/AssessmentProgress.php`) records the question ids shown, so a student who signs in again continues the same questions.
- **Submission.** `modules/CareerProfiling/api/assessment-submit.php` accepts `{questionSetId, answers: [{questionId, answer}]}`. The set must still be the active one: its fingerprint must match the questions active now. It is not tied to the sign-in session, so continuing from saved progress after signing in again still works. Every question must be answered once, with an integer from 1 to 5. Anything else is refused: a missing, duplicate or foreign answer, or an old page sending positional answers. Access-code and retake rules are unchanged.
- **What each attempt stores.** Its `question_set_id`, its answers (encrypted, by question id, in the order shown) and `scoring_version` (`riasec-10-50-v1`). `AssessmentVersion::reproduce()` re-scores an attempt from storage.
- **Legacy attempts.** Attempts saved before this change have NULL in these columns and are reported as legacy. No set or answers are invented for them; their stored scores remain usable.

## 3. Ranked interests and the ordered Holland code

`modules/CareerProfiling/lib/RiasecProfile.php` is the single implementation used by the assessment result, the results
page, the API, the dataset export and the Prediction Module's inputs.

- **What it reports:** the ranking of all six scores (highest first), plus top 1, top 2, top 3 and the three-letter ordered code.
- **Ties:** equal scores keep the order R, I, A, S, E, C. This convention keeps results compatible with earlier ones; **it does not mean the earlier letter is the stronger interest**.
- **Provisional codes:** the code is marked provisional when equal scores decide its order (`orderTie`) or which letter is third (`thirdPlaceTie`). The results page explains which letters tied.
- **Not a suitability label:** a student's top-three code is not a counselor-approved suitability label and is not used as one.

## 4. The CBF method

**Status: final (2026-10-25).** Binary program vectors from the counselors' approved codes (all 32
confirmed against the counselors' list, §12.1), the student's six stored scores ÷ 10, cosine
similarity with every program, Best RIASEC Match = every program tied at the highest similarity,
Alternative Courses = the next-highest, ties all kept and listed by the counselors' 1-2-3 letter
ranking, Preferred Course separate. No weights, no other inputs. Awaiting only the adviser's sign-off.

**Changing a program's code** (Career Dataset page; the Admin and Guidance Counselors by default,
Facilitators view only; set in Security Configuration under "Career Profile Input"):
- The code must be three different letters from R, I, A, S, E, C; the page asks for confirmation
  and reminds staff to use only the code approved by the Guidance Office.
- The Audit Log records what changed, old and new (e.g. "Holland code: SEA → ESA").
- Every saved recommendation is recomputed at once with the new code (a new row per student; older
  rows stay as history; nothing is sent to students), and a second audit entry records how many.
- `db/migrate_final_holland_codes.php` sets the 32 approved codes; run it once on a new database
  only, because running it again would undo later edits made on the Career Dataset page.

`modules/ContentBasedFiltering/lib/CBFEngine.php`, `config/cbf.php`, `db/program_codes.php`.

- **Student vector:** the six stored scores ÷ 10.
- **Program vectors:** binary vectors from the counselor-approved three-letter codes (1 for each letter present).
- **Similarity:** cosine similarity with every program.
- **Best RIASEC Match:** every program tied at the highest similarity.
- **Alternative Courses:** every program at the next-highest similarity.
- **Order inside a tie (1-2-3 letter ranking):** programs with the same similarity are listed by the
  counselors' letter order. A program whose 1st letter is the student's 1st-ranked interest comes
  first, then one whose 2nd letter matches the 2nd, then the 3rd (compared in that order), then the
  program-list order (`CBFEngine::letterOrderMatch`).
  - Equal scores share a rank, so a tie between the student's scores favours neither letter.
    For a flat profile every code matches, so the list order is kept.
  - It changes no cosine value and no membership: every tied program stays in Best Match or
    Alternatives. There are no numeric weights.
- **Preferred Course:** shown separately and never enters the calculation.

**Worked example (student S = 45, I = 40, R = 30, C = 25, A = 20, E = 15; code SIR):**
- Student vector [3.0, 4.0, 2.0, 4.5, 1.5, 2.5]; ‖S‖ = √57.75 = 7.5993.
- BS Nursing (SIR), BS Physical Therapy (SIR) and BS Biology (IRS) all have S·C = 3.0 + 4.0 + 4.5 = 11.5
  and cosine 11.5 ÷ (7.5993 × 1.7321) = 0.8737, so all three are the Best RIASEC Match.
- Inside the tie, Nursing and Physical Therapy (1st, 2nd and 3rd letters match) are listed before
  Biology (none match). BS Pharmacy (ISC, 0.8357) is the Alternative.

**Limitation:** a binary vector records which interests a code contains, not their priority. IRC, RIC
and ICR have identical vectors and always tie, although their ordered codes differ. Ties are kept;
the 1-2-3 ranking only orders the tied programs, it never removes or demotes one.

**Explanation shown, without changing the method:**
- Each program card shows the approved ordered code and its letters by name.
- When the student's code is not decided by ties, a program whose code has the same letters in the same order is marked "Same letters in the same order as your code". Otherwise the card says which of its 1st, 2nd and 3rd letters match the student's.
- The tie note on the results page explains the 1-2-3 order and that all tied programs are equally good matches.
- Not having a letter match is not evidence against a program.
- Letter-order **weights** (e.g. 3-2-1, which would change cosine values) are **not** implemented; they would need a separately approved method.

**Cosine similarity** is a measure of similarity between two interest patterns. It is not accuracy,
not a probability of success and not a percentage fit, and it is not shown to students.

## 5. Flat and insufficiently differentiated profiles

- **All six scores equal:** the student vector points equally at every interest. Every three-letter binary program vector then has cosine 3 ÷ (√6 × √3) ≈ 0.7071, so all 32 programs tie. A high similarity value alone therefore does not mean a clear recommendation.
- **Detected and handled** (`RiasecProfile`, results page):
  - **flat** (all equal) and **all-minimum** (every answer "Dislike"): all tied programs are still listed, with a message that the answers do not single out programs and a link to a Guidance Counselor;
  - **ties that decide the code:** the code is marked provisional and the tie is explained;
  - **missing or invalid scores:** refused with a reason; no value is substituted.
- **Records are kept.** The assessment record is kept and the student is not excluded from any evaluation.
- **Pending decision:** broader "low differentiation" thresholds (e.g. a small spread between the highest and lowest scores) are **not** implemented. They need counselor validation before any threshold is used.

## 6. Prediction Module (Weka J48), staff only

`modules/ContentBasedFiltering/lib/PredictionModel.php`, `config/prediction_model.json`, `tools/weka/` (see `tools/weka/README.md`).

- **What it predicts:** the student's stated **intended/preferred course** (dataset column "Actual Course"). Not verified enrolment, and not an independently assessed suitable course.
- **Inputs:** `top3-binary`, i.e. 1 for each of the three highest RIASEC scores (ties in R, I, A, S, E, C order), 0 otherwise. This allows only 20 input patterns.
- **How it runs:** the deployment holds Weka's own prediction and class probabilities for those 20 patterns.
- **Raw-score models cannot be deployed this way:**
  - the export requires `--encoding top3-binary`;
  - it refuses a tree whose splits do not fit 0/1 inputs (e.g. `C <= 12`);
  - the application refuses such a file at run time.
- **What staff see:** the model's encoding, target, version, training record count (as declared by the team) and evaluation protocol. Anything not recorded is shown as unknown.
- **Students never receive it.** It never changes the CBF results or the Preferred Course.

**Installed model (final):** `models/ProfilePath_J48_Binary_393.model`, J48 `-C 0.1 -M 10`, Weka 3.9.6,
trained on 393 students, 28 courses; 7 leaves, so it predicts 7 different courses.

| 10-fold CV, seeds 1–10 | Accuracy mean (sd) | Kappa | Course in top 3 |
|---|---|---|---|
| J48 (deployed) | 17.12% (1.24) | 0.107 | 40.89% |
| ZeroR baseline | 7.76% (0.18) | −0.007 | 24.45% |
| Highest possible with these inputs (ceiling, on the training data itself) | 24.68% | | 53.94% |

- J48 predicts the stated course about 2.2 times as often as ZeroR. It is close to the best
  possible rule under the same cross-validation (17.91%), so the limit is the inputs, not the
  algorithm's settings.
- **80% target:** not reachable with this target and these inputs by any classifier (ceiling
  24.68%). Meeting it would need a different target or different inputs, decided by the adviser.
- **Instruments:** the training data are O\*NET Mini-IP scores (0–20 per type) for the same 30 items
  ProfilePath uses (1–5 answers, stored 10–50). The conversion (stored = 2 × O\*NET + 10, §1.1)
  keeps the order of the types, so the top-three inputs are the same on both scales; only the
  answer labels are reworded (minor limitation). 84 of 393 training students (21%) tie for 3rd
  place, which the R, I, A, S, E, C rule decides.
- Full figures and the retraining steps: `tools/weka/README.md`. Why the accuracy is low (related
  studies) and recommendations: §10 and §11.

## 7. What each evaluation shows

| Evaluation | What it establishes | What it does not establish |
|---|---|---|
| Scoring and implementation correctness (tests, worked examples) | The software computes scores, codes, cosine values and ties as specified | That any recommendation suits a student |
| Agreement with the approved program mappings | Program vectors match the counselor-approved codes | Independent validation of the codes or the method |
| Intended-course prediction (Weka J48 accuracy, top-3, MRR vs ZeroR) | How well RIASEC top-three patterns predict students' stated intended courses | Suitability; agreement with counselors; academic success |
| Independent counselor suitability evaluation (not yet done) | Whether counselors, judging independently, find the recommended programs appropriate | Admission or success outcomes |
| Cosine similarity | How similar a student's interest pattern is to a program code | Accuracy, probability or percentage fit |

Outputs derived from the approved mappings must not be reported as independent counselor
validation. No claim of 80% accuracy is supported: that would need a defined target, suitable labels
and an appropriate independent evaluation.

## 8. Manuscript alignment

| Topic | Manuscript (as reviewed) | Repository | Action |
|---|---|---|---|
| CBF program vectors | 3–2–1 weighted vectors by letter position | Binary vectors (letter present = 1); the 1-2-3 letter ranking orders programs inside a tie | Correct the method description, formulas and worked examples to binary vectors (lowest possible top cosine 0.5500; flat profile 0.7071) and add the 1-2-3 tie order (§4). 3–2–1 weights are not implemented |
| Student vector | (check wording) | Six stored scores ÷ 10 | Describe the actual scores, not top-three only |
| Ties | (check wording) | Ties kept; IRC/RIC/ICR always tie; listed by the 1-2-3 letter ranking | Add the limitation and the tie order in §4 |
| Weka inputs | Top-three RIASEC attributes | `top3-binary` model deployed | Describe as top-three binary; describe any raw-score experiment separately as exploratory, not deployed |
| Weka target | (check wording) | Stated intended/preferred course | Do not call it actual or suitable course |
| Weka results | 296-record model (17.97% vs ZeroR 7.23%) or older 291-record results | 393-record model: 17.12% (sd 1.24) vs ZeroR 7.76%, kappa 0.107, top 3 40.89%; ceiling 24.68% | Replace the results table and model description (7 leaves); report the ceiling and explain the gap with the related studies in §10; add the recommendations in §11 |
| Evaluation | (check claims) | See §7 | Remove or qualify any 80% accuracy claim; separate the evaluation types |
| Assessment scoring | (check wording) | §1 table | Use the stored 10–50 scale and the formula in §1 |

The section numbers depend on the manuscript version; map these topics to its Methodology
(system design, CBF, prediction model), Results and Discussion chapters.

## 9. References and their limits

The links below were supplied by the team. They could not be opened from the build environment
(network policy), so check each against the source before citing.

- Rounds, J., Hoff, K., & Lewis, P. (2021). *O\*NET Interest Profiler Manual*. National Center for O\*NET Development. https://www.onetcenter.org/dl_files/IP_Manual.pdf (Chapter 3: scoring and reporting)
- Rounds, J., Wee, S., Cao, M., Song, C., & Lewis, P. *Development of an O\*NET Mini Interest Profiler (Mini-IP) for Mobile Devices: Psychometric Characteristics*. National Center for O\*NET Development. https://www.onetcenter.org/dl_files/Mini-IP.pdf (the 30 items, Appendix A; computer scoring with answers 0 = Strongly Dislike … 4 = Strongly Like; check the year on the title page)
- Gregory, C., & Lewis, P. (2016). *Linking Client Assessment Profiles to O\*NET Occupational Profiles*. National Center for O\*NET Development. https://www.onetcenter.org/dl_files/Mini-IP_Linking.pdf
- Lops, P., de Gemmis, M., & Semeraro, G. (2011). Content-based Recommender Systems: State of the Art and Trends. In *Recommender Systems Handbook* (Springer). https://doi.org/10.1007/978-0-387-85820-3_3

**Limits when citing them:**
- **Interest Profiler Manual:** ranked-code reporting (high-point codes) supports reporting an ordered code. It does not establish numerical weights such as 3–2–1.
- **Linking report:** describes correlation-based matching of client profiles to O\*NET occupational profiles. It is not a validation of this system's binary-cosine program method.
- **Lops et al.:** describes content-based recommendation in general. It supports the CBF framing, not these program codes or results.

## 10. Why the prediction accuracy is below the 80% target (related literature)

The adviser asked that, if the 80% accuracy target is not reached, the study explain the low
accuracy with related studies (possible causes include a small dataset and missing attributes).
This section collects the evidence from the study's own data and the related literature.

### 10.1 Evidence from the study's data (393 records)

| Finding | Figure | Source |
|---|---|---|
| J48 accuracy (10-fold CV, seeds 1–10) | 17.12% (sd 1.24) vs ZeroR 7.76%; kappa 0.107; top 3 40.89% | §6, `tools/weka/README.md` |
| Weka Experimenter, J48 vs ZeroR (corrected paired t-test, 0.05) | J48 17.12 (SD 4.45 over 100 folds) significantly better than ZeroR 7.76 (0.58); t = 6.04 | `tools/weka/README.md` |
| Weka Experimenter, kappa | J48 0.11 (0.05) significantly better than ZeroR 0.00 (0.00): agreement beyond chance, "slight" on the Landis & Koch (1977) scale | team's Weka, 2026-10-25 |
| Highest accuracy any classifier can reach with these inputs | 24.68% (top 3: 53.94%) | `evaluation/accuracy_ceiling.py` |
| Best possible rule under the same cross-validation | 17.91% (sd 0.78): J48 is close to it | same |
| Input patterns | only 20; e.g. the 51 students with A, S, E chose 17 different courses | same |
| Courses with fewer than 10 students | 12 of 28 (Marketing 1; Hospitality Management and Operations Management 2 each) | dataset check |
| Courses the tree can predict | 7 of 28 (J48 `-M 10` needs at least 10 students per leaf) | §6 |
| Students whose top three is decided by a tie for 3rd place | 84 of 393 (21%) | `cbf_eval.py arff` |
| Learning curve (J48, 10-fold CV; 10 random subsamples per size) | 98 students 15.00% (sd 3.79); 197: 15.26% (1.52); 295: 17.22% (2.15); all 393: 17.12% (1.24) | exploratory run, 2026-10-24 |

The learning curve levels off from about 300 students: adding students *of the same kind* (same
inputs, same target) is not expected to approach 80%. The limit is set mainly by the inputs: students
with identical inputs chose different courses, an error no classifier can remove (the Bayes error).

### 10.2 Related studies (2023–present)

Only studies published from 2023 onward are used as related studies (adviser's requirement). Older
works that are a theory, an instrument or a statistical convention rather than a related study are
listed separately in §10.4.

1. **Interests alone predict career choice only moderately, even with large samples and machine learning.**
   - Song, Shin, Tang, Hanna & Behrend (2024), *Personnel Psychology*: reviewing earlier work, they
     note that interest inventories predicted career choice correctly about 45% of the time when a
     "hit" means matching the highest RIASEC category (Hanna & Rounds, 2020, as cited in Song et al.,
     2024). In their own sample of 81,267 people, machine learning improved prediction overall but
     mainly for common job categories, and under-predicted rare ones.
   - Tuhame, Kayondo, Habinka & Maiga (2025), *Journal of Information Systems and Informatics*: in
     717 Ugandan secondary and university students, career preferences and RIASEC personality
     profiles were only weakly related (Kendall's τ = 0.394); nearly 50% preferred Investigative or
     Realistic careers but only 28% had matching profiles.
   - *This study:* 28 exact courses from top-three inputs is a harder task; J48's 17.12% is about
     4.8 times a uniform 1-in-28 guess (3.6%). Students' stated courses often do not follow their
     interest profile, which caps any interest-based prediction of the stated course.
2. **More classes, lower accuracy.**
   - University of Jordan study (2025; machine-learning prediction of the best-fit engineering major
     from 25 vocational-interest features, 734 students): test accuracy fell from 91.3% for 2 majors
     to 87.7%, 82.7%, 77.7%, 72.9% and 65.0% for 3 to 7 majors. (Venue listed as *SN Computer
     Science*; confirm the authors before citing.)
   - *This study:* 28 courses, four times the classes of that study's hardest setting.
3. **Missing attributes: course choice depends on more than interests.**
   - Leighton & Speer (2023), EdWorkingPaper 23-767: students' family background, especially
     parents' education, is related to which college major they choose.
   - *This study:* the dataset has only the six RIASEC scores and the stated course: no grades, SHS
     strand, parents' education, family income or self-efficacy.
4. **Information lost by reducing scores to top-three yes/no.**
   - Ma et al. (2023), *Journal of Clinical Epidemiology*: categorizing (most often dichotomizing)
     continuous predictors was the most common practice in 118 prediction-model studies; it discards
     information and lowers predictive accuracy.
   - *This study:* "R = 1" does not show how strong R was; 21% of students' top three depend on a tie.
5. **Few students in many classes, and more data of the same kind does not help.**
   - Gao et al. (2025), *Frontiers of Computer Science* (survey of imbalanced-data learning): models
     trained on skewed class sizes favour the large classes.
   - Rajput, Wang & Chen (2023), *BMC Bioinformatics*: accuracy rose with sample size only for data
     that separate the classes well; for data with weak discriminative power, accuracy stayed poor
     and did not improve with more samples.
   - *This study:* 12 of 28 courses have fewer than 10 students and are never predicted; the
     learning curve levels off at about 17% (§10.1), as Rajput et al. found for weakly separable data.
6. **A limit no classifier can exceed when inputs overlap (the Bayes error).**
   - Ishida et al. (2023), ICLR: the Bayes error is the lowest error any classifier can achieve and
     is a yardstick for judging how far a model can improve.
   - *This study:* the ceiling of 24.68% (§10.1) is that limit for these inputs, measured on the data.

### 10.3 Draft paragraph (Results / Discussion)

> The J48 model achieved a mean accuracy of 17.12% (SD 4.45) under 10-fold cross-validation
> repeated ten times, compared with 7.76% for the ZeroR baseline, below the 80% target. This is
> consistent with recent research: interest inventories predict career choice correctly only
> about 45% of the time even when a hit means matching the highest RIASEC category (Hanna & Rounds,
> 2020, as cited in Song et al., 2024), and machine learning improves mainly the common categories
> (Song et al., 2024); students' stated career preferences are only weakly related to their RIASEC
> profiles (Tuhame et al., 2025); accuracy also falls as the number of majors to choose from grows (University of
> Jordan study, 2025). Course choice depends on factors not in the dataset, such as family
> background (Leighton & Speer, 2023). Representing each student only by their top three RIASEC
> types discards information (Ma et al., 2023), and twelve of the 28 courses had fewer than ten
> students, which limits what a classifier can learn (Gao et al., 2025). Accuracy levelled off at
> about 17% from roughly 300 students, as expected for data with weak discriminative power (Rajput
> et al., 2023), and an analysis of the dataset showed that no classifier using these inputs could
> exceed 24.68%, the Bayes-error limit of these inputs (Ishida et al., 2023). J48's 17.12% approaches
> the best achievable rule (17.91%).

### 10.5 Why the significance test matters

With an accuracy far below the target, the first question a panel will ask is whether the model
learned anything or only guessed. The Weka Experimenter answers it with evidence:

1. **A fair baseline.** ZeroR always predicts the most common course (Architecture) and is right
   7.76% of the time. A model that learned something must do better than this.
2. **A reliable estimate.** One cross-validation run can be lucky (18.83%) or unlucky (14.50%). Ten
   runs of 10 folds (100 tests, seeds 1–10 fixed in advance) give 17.12%, which does not depend on
   one chosen run.
3. **Evidence that the difference is real.** The corrected paired t-test (Nadeau & Bengio's
   correction, used by Weka's Paired T-Tester (corrected)) compares J48 and ZeroR on the same 100
   test folds. J48 is significantly better (t = 6.04, df 99, p < .05), so RIASEC top-three patterns
   carry real information about students' stated intended courses.

**How it fits the findings:** the model did not reach 80%, but it performs significantly better
than the baseline; its accuracy is limited by the inputs (ceiling 24.68%, §10.1) for reasons the
related studies explain (§10.2).

**What it does not show:** that 17% is a high accuracy; that the predicted course suits the
student (the target is the stated intended course); or that the model can replace counselor
judgement. The model therefore stays a supporting, staff-only tool.

**What to report in Chapter 4, in order of importance:** (1) the Experimenter's mean accuracy
against ZeroR with the significance result; (2) the ceiling and the related studies; (3) kappa
(0.107) and top-3 accuracy (40.89%); (4) the tree figure (Weka Explorer, Visualize tree).

Sentence for the manuscript:

> Using the Weka Experimenter (10-fold cross-validation, 10 repetitions), J48 achieved a mean
> accuracy of 17.12% (SD 4.45), significantly higher than the ZeroR baseline of 7.76% (SD 0.58) by
> the corrected paired t-test (p < .05). Its mean kappa of 0.11 (SD 0.05), against 0.00 for ZeroR,
> indicates agreement beyond chance that is slight (Landis & Koch, 1977).

Table for Chapter 4 (Weka 3.9.6 Experimenter, 10-fold cross-validation × 10 repetitions,
corrected paired t-test, α = .05; SD over 100 folds):

| Classifier | Accuracy % (SD) | Kappa (SD) | vs ZeroR |
|---|---|---|---|
| ZeroR (baseline) | 7.76 (0.58) | 0.00 (0.00) | — |
| J48 (C = 0.1, M = 10) | 17.12 (4.45) | 0.11 (0.05) | significantly better |

### 10.4 References for this section

**Related studies (2023–present)**, confirmed through index and library records (the publishers'
pages could not be opened from the build environment; check details against the article):

- Gao, X., et al. (2025). A comprehensive survey on imbalanced data learning. *Frontiers of Computer Science*. https://doi.org/10.1007/s11704-025-50274-7 (preprint: arXiv:2502.08960; confirm the full author list)
- Ishida, T., Yamane, I., Charoenphakdee, N., Niu, G., & Sugiyama, M. (2023). Is the performance of my deep network too good to be true? A direct approach to estimating the Bayes error in binary classification. *International Conference on Learning Representations (ICLR 2023)*. https://arxiv.org/abs/2202.00395
- Leighton, M., & Speer, J. D. (2023). *Family background and college major choice* (EdWorkingPaper No. 23-767). Annenberg Institute at Brown University. https://edworkingpapers.com/sites/default/files/ai23-767.pdf (authors' names confirmed: Margaret Leighton and Jamin D. Speer. Not to be confused with their 2020 *European Economic Review* paper on major specificity, which is a different study)
- Ma, J., Dhiman, P., Qi, C., Bullock, G., van Smeden, M., Riley, R. D., & Collins, G. S. (2023). Poor handling of continuous predictors in clinical prediction models using logistic regression: A systematic review. *Journal of Clinical Epidemiology, 161*, 140–151. https://doi.org/10.1016/j.jclinepi.2023.07.017
- Rajput, D., Wang, W.-J., & Chen, C.-C. (2023). Evaluation of a decided sample size in machine learning applications. *BMC Bioinformatics, 24*, 48. https://doi.org/10.1186/s12859-023-05156-9
- Song, Q. C., Shin, H. J., Tang, C., Hanna, A., & Behrend, T. (2024). Investigating machine learning's capacity to enhance the prediction of career choices. *Personnel Psychology, 77*(2), 295–319. https://doi.org/10.1111/peps.12529 (confirmed by the team, 2026-10-25. The 45% figure is Hanna & Rounds (2020) as discussed by Song et al.: cite it as "Hanna & Rounds, 2020, as cited in Song et al., 2024"; only Song et al. goes in the reference list)
- Tuhame, M. K., Kayondo, B. N., Habinka, A. D. B., & Maiga, G. (2025). Career preference-personality mismatch: Leveraging the RIASEC model in IT-driven career guidance. *Journal of Information Systems and Informatics, 7*(2). https://doi.org/10.51519/journalisi.v7i2.1121
- University of Jordan study (2025). An innovative machine learning mechanism for predicting the best fit engineering major for Jordanian undergraduate students. *SN Computer Science*. https://doi.org/10.1007/s42979-025-04464-6 (authors to be filled in from the article at that link; if they cannot be confirmed, drop this study)

**Not related studies, but cited for a theory, instrument or convention** (pre-2023 is normal for
these; keep only if the adviser allows):

- Landis, J. R., & Koch, G. G. (1977). The measurement of observer agreement for categorical data. *Biometrics, 33*(1), 159–174. (the "slight / fair / moderate" scale used to describe kappa)
- Lent, R. W., Brown, S. D., & Hackett, G. (1994). Toward a unifying social cognitive theory of career and academic interest, choice, and performance. *Journal of Vocational Behavior, 45*(1), 79–122. (Social Cognitive Career Theory, if used as the framework)
- The O\*NET Mini-IP documents in §9 (the instrument).

**Replaced (published before 2023; no longer used as related studies):** Hanna & Rounds (2020),
Gasser et al. (2007), Allen & Robbins (2008), MacCallum et al. (2002), He & Garcia (2009), Figueroa
et al. (2012), Hastie et al. (2009). A Philippine study on SHS track choice (Nazareno et al.,
*Philippine Journal of Science*, 2021) and Villarama et al. (Philippine E-Journals; year not
confirmed) were also considered and not used.

## 11. Recommendations (the 80% target was not reached)

The 80% target was not reached, so, as the adviser asked, each recommendation below names the
cause it addresses and the related study (2023–present) that supports it. None is implemented;
they are recommendations for future work, and new data needs consent and data-privacy review.

1. **Add attributes beyond interests** (§10.2, item 3). Collect, with consent: SHS strand, general
   weighted average and Math/Science/English grades, and validated measures of self-efficacy and
   contextual factors (family influence, financial considerations). Retrain and compare with the
   current model under the same cross-validation.
   *Supported by:* Leighton & Speer (2023) (family background shapes major choice); Fernandez,
   Balijon & Ancho (2023) (Filipino SHS students choose careers to fit family needs); Tuhame et al.
   (2025) (preferences only weakly follow RIASEC profiles, so other factors are needed).
2. **Use the full six scores instead of top-three yes/no** (§10.2, item 4). Train on the numeric scores
   so the strength of each interest is kept. This needs a change to the deployment, which today
   accepts only top-three binary models.
   *Exploratory check (2026-10-24, same 393 records, Weka 3.9.6, 10-fold CV, seeds 1–10):* J48
   `-C 0.1 -M 10` on the raw 0–20 scores gives 16.49% (sd 1.72; 20 leaves, first split `I <= 7`), and
   with Weka's defaults (`-C 0.25 -M 2`) 13.21% (sd 1.72; 121 leaves, over-fitted). Neither is better
   than the top-three model (17.12%), so the full scores alone do not raise accuracy with J48 on this
   dataset; together with new attributes (recommendation 1) they may. A raw-score tree cannot be deployed in
   ProfilePath (the Prediction Module accepts only top-three 0/1 models).
   *Supported by:* Ma et al. (2023) (categorizing continuous predictors discards information and
   lowers predictive accuracy).
3. **Use the same instrument for training and use.** Train on results from ProfilePath's own
   assessment (10–50 scale) rather than the O\*NET website's scores (0–20), so the model sees the
   same kind of scores it is applied to. (The items are the same 30 Mini-IP items and the type
   ranking is identical, §1.1; this mainly removes the reworded-label difference.)
4. **More records, aimed at the small courses** (§10.2, item 5). The learning curve has levelled off, so
   simply adding more students of the same kind is unlikely to help much; the most useful additions
   are students in the 12 courses with fewer than 10 records. Re-run the learning curve and the
   ceiling check after each collection.
   *Supported by:* Rajput, Wang & Chen (2023) (more samples do not raise accuracy when the inputs
   separate the classes weakly); the University of Jordan study (2025) (accuracy falls as the number
   of majors grows).
5. **Handle class imbalance** (§10.2, item 5): cost-sensitive learning or resampling, applied inside each
   cross-validation training fold only, and report per-course recall, not accuracy alone.
   *Supported by:* Gao et al. (2025) (models trained on skewed classes favour the large ones);
   Song et al. (2024) (machine learning under-predicted rare career categories).
6. **Choose an outcome and measure that fit the purpose** (§10.2, items 1–2), if the adviser agrees:
   - report top-3 accuracy (40.89%) beside exact accuracy, since staff are shown the top three;
   - or predict a broader target (program group or college) with an agreed definition.
   Any change of target must be decided before measuring and reported under its own name.
   *Supported by:* Hanna & Rounds (2020, as cited in Song et al., 2024) (about 45% even for the
   highest RIASEC category); the University of Jordan study (2025) (fewer classes, higher accuracy).
7. **Report honestly.** State that the 80% target was not met, give the cross-validated result
   with ZeroR and the ceiling (24.68%), and explain it with §10. Do not report training-set
   accuracy, results from choosing seeds, or results after removing students.
   *Supported by:* Ishida et al. (2023) (the Bayes error is the limit any classifier can reach; the
   ceiling of 24.68% is that limit for these inputs).
8. **Keep the model's role limited.** It stays a supporting, staff-only prediction of the stated
   intended course, separate from the CBF recommendation, until a validated model exists.
   *Supported by:* Fernandez, Balijon & Ancho (2023) (recommend career pathing by guidance
   counselors); Tuhame et al. (2025) (guidance should not rely on RIASEC alone).
9. **Test the recommendations with students and counselors.** Measure acceptance and counselor
   agreement with the CBF's Best RIASEC Match, as a separate evaluation from prediction accuracy.
   *Supported by:* Rances et al. (2026) (a Philippine degree-program recommender evaluated through
   SHS students' acceptance of its recommendations).

## 12. Finalization checklist (open items before the system and manuscript are checked)

What is final in the repository: the CBF (binary program vectors, cosine similarity, ties kept and
listed by the 1-2-3 letter ranking) and the Prediction Module (J48, 393 records, top-3 binary,
staff only). The items below need the team, the adviser or the counselors.

### 12.1 Confirmations (the team)

| # | Item | Why it matters | Send |
|---|---|---|---|
| 1 | **Done (2026-10-24):** the 393 records used the 30-item O\*NET Mini Interest Profiler, the same items as ProfilePath | Settles the instrument limitation; conversion in §1.1 | — |
| 2 | **Tree confirmed (2026-10-25):** the team's Weka Explorer builds the same 7-leaf tree from `all_binary.arff` (screenshot). Still to send: the seed-1 output (68, 17.3028 %, Kappa 0.1103) | Confirms the team's Weka reproduces the installed model | Screenshot of the Classify output |
| 3 | **Done (2026-10-25)** on the team's Weka: Percent_correct ZeroR 7.76 (0.58), J48 17.12 (4.45) v; Kappa_statistic ZeroR 0.00 (0.00), J48 0.11 (0.05) v | The figures for Chapter 4 (§10.5 table) | — |
| 4 | **Done (2026-10-25):** the counselors' list of 32 codes matches `db/program_codes.php`, `evaluation/course_profiles.csv` and a seeded database exactly (32 of 32, including the revised BS Tourism Management SEA and BS Computer Engineering ICR). On Render, `php db/migrate_final_holland_codes.php` sets the live database to these codes (safe to run again) | The CBF uses them as the program vectors | — |
| 5 | **Mostly done (2026-10-25):** Song et al., Chayka, Leighton & Speer and Rances et al. confirmed; Hernandez & Atienza (2021) and the 2021 Kamal paper dropped as pre-2023. Still open: the authors of the University of Jordan study (DOI 10.1007/s42979-025-04464-6) and of the 2024 IEEE Access review (DOI 10.1109/ACCESS.2024.3368058) | Citations must match the articles | Authors from those two DOI pages |

### 12.2 Decisions (adviser and counselors)

| # | Decision | Current state |
|---|---|---|
| 1 | CBF: binary vectors with the 1-2-3 ranking used only to order tied programs | Final in the system (§4); adviser's sign-off pending |
| 2 | Weka: accept the 393-record model and explain the 80% gap with related studies | **Adviser (2026-10-25):** if the target is not reached, support the reasons with related studies and put them in the recommendations. Done: §10.2 (causes and studies), §11 (each recommendation cites its study) |
| 3 | Which standard deviation to report | Not yet decided. Proposed: the Experimenter's SD over 100 folds (4.45), as in the §10.5 table, which is Weka's own output |
| 4 | Whether any §11 recommendation is done now | Not decided; treated as future work (Chapter 5) |
| 5 | Pre-2023 sources for theories and instruments | **Allowed** (adviser, 2026-10-25): Landis & Koch (1977), SCCT (1994) and the O\*NET documents stay |

### 12.3 Running the updated system (the team)

1. Local: `git pull origin main`; run the five migrations (`db/setup_new_database.php` lists them);
   `php db/recompute_recommendations.php`; open the results page and a staff student profile.
2. Render: the same migrations and `php db/recompute_recommendations.php` on the live database.
3. Security: change the Render database password, the AES keys and the admin password; never
   commit `.env`.

### 12.4 Manuscript (send the latest Chapters 1–5)

- Ch. 3: CBF method (binary vectors, cosine, ties, 1-2-3 order inside a tie: §4); Weka method
  (top-3 binary inputs, 393 records, J48 -C 0.1 -M 10, 10×10-fold CV, ZeroR, corrected t-test).
- Ch. 4: CBF worked example (§4) and Tables 19–21 with binary values (lowest top cosine 0.5500);
  Weka results (§10.5 order), the ceiling, the tree figure.
- Ch. 5: the 80% gap explained with §10; recommendations from §11; limitations (§6, §10.1).

## 13. Related literature for Chapter 2 (2023–2026, local and international)

Compiled 2026-10-25 from index and library records; the publishers' pages could not be opened from
the build environment, so check each against the article (especially the items marked *check*).
Grouped by what each study supports in ProfilePath.

### 13.1 RIASEC interests and course choice

| Study | Setting | Finding | Use in ProfilePath |
|---|---|---|---|
| Fernandez, C. B., Balijon, M. B., & Ancho, I. V. (2023). Career preferences of Filipino senior high school students. *CTU Journal of Innovation and Sustainable Development, 15*(3), 81–91. https://doi.org/10.22144/ctujoisd.2023.053 | **Philippines** (Cebu City public SHS; 35 participants incl. 5 guidance counselors) | Students often choose careers that fit their families' needs, many rely on parents' choices; recommends career pathing by guidance counselors | Background and need for the system; why stated courses differ from interests (missing attributes) |
| Tuhame, M. K., Kayondo, B. N., Habinka, A. D. B., & Maiga, G. (2025). *Journal of Information Systems and Informatics, 7*(2). https://doi.org/10.51519/journalisi.v7i2.1121 | Uganda, 717 students | Preferences and RIASEC profiles only weakly related (τ = 0.394); proposes IT-driven guidance that combines RIASEC with other data | Why the stated course is hard to predict from interests; supports counselor-supported guidance |
| Chayka, R. M. (2025). From RIASEC to algorithms: A literature review of person-vocation fit (1990–2025). *Scientific Bulletin of Kherson State University. Series "Psychological Sciences"*, (2), 67–75. https://doi.org/10.32999/ksu2312-3206/2025-2-9 (confirmed) | Review of 87 studies | RIASEC congruence indices are the "gold standard" (62% of studies); a shift toward algorithmic assessment | Supports matching students to programs by RIASEC similarity (Objective 2) |
| Leighton, M., & Speer, J. D. (2023). *Family background and college major choice* (EdWorkingPaper 23-767). https://edworkingpapers.com/sites/default/files/ai23-767.pdf (names confirmed) | United States | Parents' education relates to major choice | Missing attributes (§10.2) |

### 13.2 Recommender systems for program choice (content-based filtering, hybrids)

| Study | Setting | Finding | Use in ProfilePath |
|---|---|---|---|
| Recommender system in academic choices of higher education: A systematic review (2024). *IEEE Access, 12*, 35475–35501. https://doi.org/10.1109/ACCESS.2024.3368058 (*check* the authors at this DOI. Not the 2021 ICCIS paper "Smart career guidance system" by Kamal, Naushad, Rafiq & Tahzeeb, which is a different, pre-2023 work) | Review of 56 studies (2011–2023) | Hybrid strategies performed best; most studies were lab-based; personality traits are a future direction | Positions ProfilePath's content-based (RIASEC) recommendation and its separate prediction source |
| Bucad, A. T. (2024). Towards the development of a career path recommender system for senior high school in selected public schools using multi-label classification. *International Journal of Research and Innovation in Applied Science, 9*(3), 374–382. https://doi.org/10.51584/IJRIAS.2024.90334 | **Philippines** (Valenzuela City, Grade 10) | Proposes content-based filtering with decision tree, KNN, Naïve Bayes and SVM classifiers on family background, personality, grades and IQ (a design paper; no results yet) | Local precedent for combining content-based filtering with a decision-tree classifier |
| Rances, C. A., Sipe, R. A., Baris, S., Lopez, A., Rosaldo, A. D., Bringula, R., & Ulfa, S. (2026). CoDeRS: A computing degree program recommender system using machine learning algorithms. *Frontiers in Artificial Intelligence, 9*, Article 1874202. https://doi.org/10.3389/frai.2026.1874202 (confirmed by the team) | **Philippines**, computing degree programs | A machine-learning degree-program recommender tested with 20 senior high school students from different strands; 76.2% accepted the recommendations (figures from the article page as summarized by search: *check*) | Closest local precedent: a degree-program recommender for SHS students; supports acceptance testing with students and counselors |
| Valles-Coral, M. A., et al. (2025). A hybrid machine learning model for personalized elective course recommendations in higher education. *Ingénierie des Systèmes d'Information, 30*(7), 1723–1730. https://doi.org/10.18280/isi.300705 | Peru, 120 students | PCA, K-Means and a neural network on academic records (R² 0.85); limitation: no personal interests or career goals | Shows the gap ProfilePath fills by recommending from interests (RIASEC) |

### 13.3 Predicting course, strand or major with machine learning

| Study | Setting | Finding | Use in ProfilePath |
|---|---|---|---|
| Song, Q. C., Shin, H. J., Tang, C., Hanna, A., & Behrend, T. (2024). *Personnel Psychology, 77*(2), 295–319. https://doi.org/10.1111/peps.12529 (confirmed) | 81,267 people | Interest inventories match the highest RIASEC category about 45% of the time (Hanna & Rounds, 2020, as cited); machine learning improved prediction mainly for common categories and under-predicted rare ones | Prediction Module (J48) and its limits |
| University of Jordan study (2025). *SN Computer Science*. https://doi.org/10.1007/s42979-025-04464-6 (*check* authors) | Jordan, 734 students | Interest-based prediction of engineering major: 91.3% with 2 majors down to 65.0% with 7 | Why 28 courses lowers accuracy |

### 13.4 Why accuracy is limited

Ma et al. (2023), Gao et al. (2025), Rajput, Wang & Chen (2023) and Ishida et al. (2023): see §10.2
items 4–6 and §10.4.

### 13.5 Considered and not used

Hernandez & Atienza (2021, *Applied Computer Science, 17*(4), 55–74; before 2023; a deep neural network
predicted the SHS strand from grades and family data with 83.11% accuracy); Kamal, Naushad, Rafiq &
Tahzeeb (2021, ICCIS; before 2023); Nazareno et al. (2021, *Philippine Journal of Science*; before 2023); Villarama et al.
(Philippine E-Journals; year not confirmed); a RIASEC
profile-matching decision support system for Indonesian SMA students (DOI 10.36341/rabit.v11i2.7967;
year not confirmed).

