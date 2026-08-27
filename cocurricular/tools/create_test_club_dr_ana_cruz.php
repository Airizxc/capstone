<?php
/**
 * SMS 2 - Co-Curricular: Create Test Club (Dr. Ana L. Cruz)
 * 
 * IDEMPOTENT UTILITY: Safe to run multiple times
 * 
 * Purpose: Create or retrieve a test club for adviser Dr. Ana L. Cruz
 * to enable end-to-end workflow testing between Student and Adviser sides.
 * 
 * Database: cocurricular_db.clubs
 * No modifications to any existing schema.
 */

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../includes/cocurricular-db.php';

// Test data for Dr. Ana L. Cruz
const TEST_CLUB_DATA = [
    'club_name'     => 'BCP Technology and Innovation Club',
    'category'      => 'Academic',
    'description'   => 'A student organization focused on technology, programming, innovation, and collaborative projects that develop students\' technical and problem-solving skills.',
    'adviser'       => 'Dr. Ana L. Cruz',
    'adviser_email' => 'ana.cruz@bcp.edu.ph',
    'contact_phone' => '0917-555-0201',
    'status'        => 'Active',
];

// HTML/Plain text output function
function renderOutput($title, $data) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Test Club Utility - SMS 2 Co-Curricular</title>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }
            .container {
                background: white;
                border-radius: 12px;
                box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
                padding: 40px;
                max-width: 600px;
                width: 100%;
            }
            .container h1 {
                color: #333;
                margin-bottom: 30px;
                font-size: 28px;
                border-bottom: 3px solid #667eea;
                padding-bottom: 15px;
            }
            .success {
                background-color: #d4edda;
                border: 1px solid #c3e6cb;
                color: #155724;
                padding: 15px;
                border-radius: 6px;
                margin-bottom: 30px;
            }
            .error {
                background-color: #f8d7da;
                border: 1px solid #f5c6cb;
                color: #721c24;
                padding: 15px;
                border-radius: 6px;
                margin-bottom: 30px;
            }
            .info-box {
                background-color: #f8f9fa;
                border-left: 4px solid #667eea;
                padding: 20px;
                border-radius: 6px;
                margin-bottom: 20px;
            }
            .info-row {
                display: flex;
                justify-content: space-between;
                padding: 12px 0;
                border-bottom: 1px solid #e9ecef;
            }
            .info-row:last-child {
                border-bottom: none;
            }
            .info-label {
                font-weight: 600;
                color: #667eea;
                min-width: 150px;
            }
            .info-value {
                color: #333;
                text-align: right;
                flex: 1;
                margin-left: 20px;
                word-break: break-word;
            }
            .code {
                background-color: #f4f4f4;
                padding: 2px 6px;
                border-radius: 4px;
                font-family: 'Courier New', monospace;
                font-size: 13px;
                color: #d63384;
            }
            .button-group {
                display: flex;
                gap: 10px;
                margin-top: 30px;
            }
            .button {
                flex: 1;
                padding: 12px 20px;
                border: none;
                border-radius: 6px;
                font-size: 14px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.3s ease;
            }
            .button-primary {
                background-color: #667eea;
                color: white;
            }
            .button-primary:hover {
                background-color: #5568d3;
            }
            .button-secondary {
                background-color: #e9ecef;
                color: #333;
            }
            .button-secondary:hover {
                background-color: #dee2e6;
            }
            .footer {
                margin-top: 30px;
                padding-top: 20px;
                border-top: 1px solid #e9ecef;
                text-align: center;
                font-size: 12px;
                color: #999;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <h1><?= htmlspecialchars($title) ?></h1>
            <?= $data ?>
            <div class="footer">
                <p>SMS 2 Co-Curricular Module - Test Club Utility</p>
            </div>
        </div>
    </body>
    </html>
    <?php
}

// Main execution
try {
    $pdo = cocurricularDb();

    if (!$pdo) {
        throw new Exception('Failed to connect to cocurricular_db. Check database configuration.');
    }

    // 1. Check if club already exists by adviser_email
    $checkStmt = $pdo->prepare(
        'SELECT id, club_name, category, adviser, adviser_email, contact_phone, status, created_at 
         FROM clubs 
         WHERE adviser_email = ? 
         LIMIT 1'
    );
    $checkStmt->execute([TEST_CLUB_DATA['adviser_email']]);
    $existingClub = $checkStmt->fetch();

    if ($existingClub) {
        // Club exists - just display it
        $output = '<div class="success">';
        $output .= '<strong>✓ Club already exists</strong> - No duplicate created.';
        $output .= '</div>';

        $output .= '<div class="info-box">';
        $output .= '<div class="info-row">';
        $output .= '<span class="info-label">Club ID:</span>';
        $output .= '<span class="info-value"><span class="code">' . htmlspecialchars($existingClub['id']) . '</span></span>';
        $output .= '</div>';

        $output .= '<div class="info-row">';
        $output .= '<span class="info-label">Club Name:</span>';
        $output .= '<span class="info-value">' . htmlspecialchars($existingClub['club_name']) . '</span>';
        $output .= '</div>';

        $output .= '<div class="info-row">';
        $output .= '<span class="info-label">Category:</span>';
        $output .= '<span class="info-value">' . htmlspecialchars($existingClub['category']) . '</span>';
        $output .= '</div>';

        $output .= '<div class="info-row">';
        $output .= '<span class="info-label">Adviser:</span>';
        $output .= '<span class="info-value">' . htmlspecialchars($existingClub['adviser']) . '</span>';
        $output .= '</div>';

        $output .= '<div class="info-row">';
        $output .= '<span class="info-label">Adviser Email:</span>';
        $output .= '<span class="info-value">' . htmlspecialchars($existingClub['adviser_email']) . '</span>';
        $output .= '</div>';

        $output .= '<div class="info-row">';
        $output .= '<span class="info-label">Contact Phone:</span>';
        $output .= '<span class="info-value">' . htmlspecialchars($existingClub['contact_phone']) . '</span>';
        $output .= '</div>';

        $output .= '<div class="info-row">';
        $output .= '<span class="info-label">Status:</span>';
        $output .= '<span class="info-value"><strong>' . htmlspecialchars($existingClub['status']) . '</strong></span>';
        $output .= '</div>';

        $output .= '<div class="info-row">';
        $output .= '<span class="info-label">Created:</span>';
        $output .= '<span class="info-value">' . htmlspecialchars(date('M j, Y \a\t h:i A', strtotime($existingClub['created_at']))) . '</span>';
        $output .= '</div>';

        $output .= '</div>';

        renderOutput('Test Club - Already Exists', $output);
        exit;
    }

    // 2. Club doesn't exist - create it
    $insertStmt = $pdo->prepare(
        'INSERT INTO clubs (club_name, category, description, adviser, adviser_email, contact_phone, status, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
    );

    $inserted = $insertStmt->execute([
        TEST_CLUB_DATA['club_name'],
        TEST_CLUB_DATA['category'],
        TEST_CLUB_DATA['description'],
        TEST_CLUB_DATA['adviser'],
        TEST_CLUB_DATA['adviser_email'],
        TEST_CLUB_DATA['contact_phone'],
        TEST_CLUB_DATA['status'],
    ]);

    if (!$inserted) {
        throw new Exception('Failed to insert club into database.');
    }

    $newClubId = $pdo->lastInsertId();

    // 3. Verify the club was created
    $verifyStmt = $pdo->prepare(
        'SELECT id, club_name, category, adviser, adviser_email, contact_phone, status, created_at 
         FROM clubs 
         WHERE id = ? 
         LIMIT 1'
    );
    $verifyStmt->execute([$newClubId]);
    $newClub = $verifyStmt->fetch();

    if (!$newClub) {
        throw new Exception('Club was inserted but could not be retrieved.');
    }

    // 4. Display success
    $output = '<div class="success">';
    $output .= '<strong>✓ Club created successfully!</strong> The test club is now available for Student and Adviser workflow testing.';
    $output .= '</div>';

    $output .= '<div class="info-box">';
    $output .= '<div class="info-row">';
    $output .= '<span class="info-label">Club ID:</span>';
    $output .= '<span class="info-value"><span class="code">' . htmlspecialchars($newClub['id']) . '</span></span>';
    $output .= '</div>';

    $output .= '<div class="info-row">';
    $output .= '<span class="info-label">Club Name:</span>';
    $output .= '<span class="info-value">' . htmlspecialchars($newClub['club_name']) . '</span>';
    $output .= '</div>';

    $output .= '<div class="info-row">';
    $output .= '<span class="info-label">Category:</span>';
    $output .= '<span class="info-value">' . htmlspecialchars($newClub['category']) . '</span>';
    $output .= '</div>';

    $output .= '<div class="info-row">';
    $output .= '<span class="info-label">Adviser:</span>';
    $output .= '<span class="info-value">' . htmlspecialchars($newClub['adviser']) . '</span>';
    $output .= '</div>';

    $output .= '<div class="info-row">';
    $output .= '<span class="info-label">Adviser Email:</span>';
    $output .= '<span class="info-value">' . htmlspecialchars($newClub['adviser_email']) . '</span>';
    $output .= '</div>';

    $output .= '<div class="info-row">';
    $output .= '<span class="info-label">Contact Phone:</span>';
    $output .= '<span class="info-value">' . htmlspecialchars($newClub['contact_phone']) . '</span>';
    $output .= '</div>';

    $output .= '<div class="info-row">';
    $output .= '<span class="info-label">Status:</span>';
    $output .= '<span class="info-value"><strong>' . htmlspecialchars($newClub['status']) . '</strong></span>';
    $output .= '</div>';

    $output .= '<div class="info-row">';
    $output .= '<span class="info-label">Created:</span>';
    $output .= '<span class="info-value">' . htmlspecialchars(date('M j, Y \a\t h:i A', strtotime($newClub['created_at']))) . '</span>';
    $output .= '</div>';

    $output .= '</div>';

    // 5. Show next steps
    $output .= '<div class="info-box" style="background-color: #e7f3ff; border-left-color: #0066cc;">';
    $output .= '<strong>Next Steps for Testing:</strong>';
    $output .= '<ul style="margin: 15px 0 0 20px; color: #333;">';
    $output .= '<li style="margin-bottom: 8px;">Log in as a <strong>Student</strong> account</li>';
    $output .= '<li style="margin-bottom: 8px;">Navigate to: <strong>Co-Curricular → Club Directory</strong></li>';
    $output .= '<li style="margin-bottom: 8px;">Find: <strong>' . htmlspecialchars(TEST_CLUB_DATA['club_name']) . '</strong></li>';
    $output .= '<li style="margin-bottom: 8px;">Submit a membership application</li>';
    $output .= '<li style="margin-bottom: 8px;">Log in as <strong>' . htmlspecialchars(TEST_CLUB_DATA['adviser']) . '</strong> (adviser account)</li>';
    $output .= '<li style="margin-bottom: 8px;">Navigate to: <strong>Faculty → Co-Curricular → Membership Applications</strong></li>';
    $output .= '<li style="margin-bottom: 8px;">View and approve/reject the student\'s application</li>';
    $output .= '<li>Log back in as the student to see the updated status</li>';
    $output .= '</ul>';
    $output .= '</div>';

    renderOutput('Test Club Created - Ready for Testing', $output);
    exit;

} catch (Throwable $e) {
    $errorMsg = $e->getMessage();
    $output = '<div class="error">';
    $output .= '<strong>✗ Error:</strong> ' . htmlspecialchars($errorMsg);
    $output .= '</div>';

    $output .= '<div class="info-box" style="background-color: #fff3cd; border-left-color: #ff6b6b;">';
    $output .= '<strong>Troubleshooting:</strong>';
    $output .= '<ul style="margin: 15px 0 0 20px; color: #333;">';
    $output .= '<li>Ensure <span class="code">cocurricular_db</span> exists and is accessible</li>';
    $output .= '<li>Verify database credentials in <span class="code">config/database.php</span></li>';
    $output .= '<li>Check that the <span class="code">clubs</span> table exists in <span class="code">cocurricular_db</span></li>';
    $output .= '<li>Run the co-curricular database setup if needed: <span class="code">modules/cocurricular/database/cocurricular.sql</span></li>';
    $output .= '</ul>';
    $output .= '</div>';

    renderOutput('Test Club - Error', $output);
    exit;
}
?>
