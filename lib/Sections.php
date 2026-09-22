<?php

// The school offers exactly these 7 sections, each belonging to exactly one
// strand. Server-side source of truth for every place a section is accepted
// (registration, exam scheduling, staff corrections) — client-side dropdowns
// can't be trusted alone, and a typo'd section silently never matches a real
// student (the exam access-code gate compares it exactly).
const SECTIONS_BY_STRAND = [
    'STEM' => ['S1114', 'S1109'],
    'ABM' => ['A1101', 'A1102'],
    'ICT' => ['I1101', 'I1102'],
    'HUMSS' => ['H1102'],
];
