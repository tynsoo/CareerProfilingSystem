<?php
/**
 * One-time backfill: populates programs.description_enc with real program
 * summaries sourced from Mapúa MCL's own academics pages (mcl.edu.ph),
 * paraphrased and condensed per program. Safe to re-run — always
 * overwrites with the same text, matched by program title.
 */
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/Crypto.php';

$descriptions = [
    'BA Communication' => 'Equips students with the skills to communicate effectively across a variety of settings and media, with coursework in research methods, ethics, and professional practice.',
    'BS Multimedia Arts' => 'A four-year interdisciplinary program teaching students to create and use digital media grounded in design, technology, and the visual arts to tell stories and express ideas creatively.',
    'BS Computer Science' => 'Prepares students to design, develop, and analyze computer systems and software applications as computing professionals.',
    'BS Information Technology' => 'Prepares students to become IT professionals who can plan, develop, and maintain software applications for Windows, web, and mobile platforms.',
    'BS Biology' => 'A generalized framework of study that grounds students in the biological, natural, and physical sciences.',
    'BS Medical Technology' => 'A 4-year program training students in laboratory sciences for medical diagnosis, covering microbiology, hematology, and clinical chemistry.',
    'BS Pharmacy' => 'Combines science-based coursework with hands-on training in pharmacies, hospitals, and labs, building a foundation in pharmacology and pharmaceutics for future pharmacists.',
    'BS Physical Therapy' => 'Combines general education with professional courses in the physical, social, and health sciences, including a 1,500-hour internship for hands-on clinical experience.',
    'BS Psychology' => 'Provides a solid, broad foundation in the concepts, theories, and principles of psychology.',
    'BS Nursing' => 'Provides a strong educational foundation combining theoretical knowledge with practical application, preparing graduates for healthcare careers locally and internationally.',
    'BS Accountancy' => 'A rigorous program producing top certified public accountants, preparing graduates for careers such as budget analyst, bank specialist, auditor, tax agent, and real estate appraiser.',
    'BS Accounting Information System' => 'Combines business, accounting, and computer systems knowledge, training graduates to work across accountancy and IT and to coordinate technology-driven business decisions.',
    'BS Business Administration Major in Financial Management' => 'Builds core business skills and practical tools for a career in financial management, one of the college\'s three BSBA majors alongside Operations and Marketing Management.',
    'BS Business Administration Major in Operations Management' => 'A three-year-and-one-term program covering the functional areas of business, with a focus on product development and supply chain management for managerial or entrepreneurial careers.',
    'BS Business Administration Major in Sustainability Management' => 'For students passionate about business and environmental responsibility, building the knowledge and skills to lead in sustainable business practices.',
    'BS Hospitality Management' => 'Covers managing daily operations for hotels, restaurants, catering, events, and cruise ships, balancing business and management fundamentals with technical and soft skills.',
    'BS Tourism Management' => 'Prepares dynamic leaders for the tourism, travel, and hospitality industry, with sustainability principles integrated throughout the curriculum.',
    'BS International Business' => 'Prepares students for leadership roles in multinational corporations, government, and NGOs, building the skills to thrive in a globally interconnected economy.',
    'BS Business Analytics with Artificial Intelligence' => 'Develops data-analysis skills for business, research, and government roles, grounded in linear algebra, statistical inference, data mining, and machine learning.',
    'BS Marketing' => 'Develops strategic thinking so students can apply marketing concepts to real business challenges and understand what drives successful companies.',
    'BS Architecture' => 'A five-year professional undergraduate program providing the comprehensive education needed to practice architecture.',
    'BS Chemical Engineering' => 'Prepares students for careers in the chemical industry, building skills in basic engineering and chemical production.',
    'BS Civil Engineering' => 'Provides a high-quality education preparing students for a wide range of careers in civil engineering.',
    'BS Mechanical Engineering' => 'Teaches students to design, build, and improve machines, processes, and systems involving mechanical forces, work, and energy.',
    'BS Electrical Engineering' => 'Covers the technology and applied science of electrical phenomena, built on a strong foundation in mathematics and physical science.',
    'BS Electronics Engineering' => 'Provides a strong foundation in the principles and practices of electronics engineering.',
    'BS Industrial Engineering' => 'Trains students to improve and install products, processes, and integrated systems of people, materials, information, equipment, and energy.',
    'BS Computer Engineering' => 'Provides the skills and competencies needed in computer, communication, and information technology.',
    'BS Aeronautical Engineering' => 'Offers a comprehensive education in aeronautics, equipping students with the knowledge and practical skills to excel in the aviation industry.',
    'BS Aviation Management' => 'Covers airport management, airline operations, and revenue management, spanning financial management, flight logistics, aircraft maintenance, and customer service.',
    'BS Marine Engineering' => 'Covers the mandatory education and training for Officers in Charge of an Engineering Watch under Regulation III/1 of the STCW Convention, 1978, as amended.',
    'BS Marine Transportation' => 'Covers the mandatory education and training for Officers in Charge of a Navigation Watch under Regulation II/1 of the STCW Convention, 1978, as amended.',
];

$pdo = Database::get();
$rows = $pdo->query('SELECT id, title_enc FROM programs')->fetchAll();
$update = $pdo->prepare('UPDATE programs SET description_enc = ?, updated_at = NOW() WHERE id = ?');

$updated = 0;
$unmatched = [];
foreach ($rows as $row) {
    $title = Crypto::dec($row['title_enc']);
    if (isset($descriptions[$title])) {
        $update->execute([Crypto::enc($descriptions[$title]), $row['id']]);
        $updated++;
    } else {
        $unmatched[] = $title;
    }
}

echo "Updated $updated program(s).\n";
if ($unmatched) {
    echo "No description found for: " . implode(', ', $unmatched) . "\n";
}
