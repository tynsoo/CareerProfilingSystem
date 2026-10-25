<?php
/**
 * Other names a course may have in the Weka dataset's class column, keyed by the
 * program title stored in `programs` (see db/program_codes.php). Matching ignores
 * case, spaces, dots and hyphens, and the program title itself always matches.
 *
 * Same list as evaluation/cbf_eval.py's COURSE_ALIASES (which the dataset tools use),
 * so a class label written by those tools always resolves to a program here.
 * Ambiguous abbreviations (e.g. BSMT) are deliberately left out.
 *
 * The misspelled names (Pyschology, Managment, Managements) were class labels in the earlier
 * 296-record model and dataset; they stay matched so older data and models still resolve.
 */
return [
    'BA Communication' => ['BACOMM', 'ABCOMM'],
    'BS Multimedia Arts' => ['B Multimedia Arts', 'BMMA', 'BSMMA'],
    'BS Computer Science' => ['BSCS'],
    'BS Information Technology' => ['BSIT'],
    'BS Biology' => ['BSBIO'],
    'BS Medical Technology' => ['BSMEDTECH'],
    'BS Pharmacy' => ['BSPHARMA'],
    'BS Physical Therapy' => ['BSPT'],
    'BS Psychology' => ['BSPSYCH', 'BS Pyschology'],
    'BS Nursing' => ['BSN'],
    'BS Accountancy' => ['BSA'],
    'BS Accounting Information System' => ['BSAIS'],
    'BS Business Administration Major in Financial Management' => ['BSBA Major in Financial Management', 'BSBAFM', 'BS Business Administration Major in Financial Managements'],
    'BS Business Administration Major in Operations Management' => ['BSBA Major in Operations Management', 'BSBAOM', 'BS Business Administration Major in Operations Managements'],
    'BS Business Administration Major in Sustainability Management' => ['BSBA Major in Sustainability Management', 'BSBASM'],
    'BS Hospitality Management' => ['BSHM', 'BS Hospitality Managment'],
    'BS Tourism Management' => ['BSTM'],
    'BS International Business' => ['BSIB'],
    'BS Architecture' => ['BSARCH'],
    'BS Chemical Engineering' => ['BSCHE'],
    'BS Civil Engineering' => ['BSCE'],
    'BS Mechanical Engineering' => ['BSME'],
    'BS Electrical Engineering' => ['BSEE'],
    'BS Electronics Engineering' => ['BSECE'],
    'BS Industrial Engineering' => ['BSIE'],
    'BS Computer Engineering' => ['BSCPE', 'BSCOE'],
    'BS Aeronautical Engineering' => ['BSAE', 'BSAERO'],
    'BS Marine Engineering' => ['BSMARE'],
];
