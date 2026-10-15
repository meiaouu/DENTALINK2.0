<?php
require '../../conn/conn.php';
date_default_timezone_set('Asia/Manila');

// Include PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require '../../vendor/autoload.php'; // If using Composer
// OR if not using Composer, include manually:
// require '../../PHPMailer/src/PHPMailer.php';
// require '../../PHPMailer/src/SMTP.php';
// require '../../PHPMailer/src/Exception.php';

function h($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

// Get clinic name for display
$clinicName = 'Wansi Calacat Dental Clinic';
if ($conn->query("SHOW TABLES LIKE 'clinic_settings'")->num_rows > 0) {
    $settings = $conn->query("SELECT clinic_name FROM clinic_settings WHERE id = 1")->fetch_assoc();
    if ($settings && $settings['clinic_name']) {
        $clinicName = $settings['clinic_name'];
    }
}

// Handle backup actions
$message = '';
$messageType = '';

// Function to create database backup
function createBackup($conn) {
    $tables = [];
    $result = $conn->query("SHOW TABLES");
    while ($row = $result->fetch_row()) {
        $tables[] = $row[0];
    }
    
    $backup = "-- Database Backup\n";
    $backup .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    $backup .= "-- Database: " . $conn->query("SELECT DATABASE()")->fetch_row()[0] . "\n\n";
    
    foreach ($tables as $table) {
        // Get table structure
        $result = $conn->query("SHOW CREATE TABLE `$table`");
        $row = $result->fetch_row();
        $backup .= "-- Table: $table\n";
        $backup .= "DROP TABLE IF EXISTS `$table`;\n";
        $backup .= $row[1] . ";\n\n";
        
        // Get table data
        $result = $conn->query("SELECT * FROM `$table`");
        if ($result->num_rows > 0) {
            $backup .= "-- Data for table $table\n";
            $columns = [];
            $colResult = $conn->query("SHOW COLUMNS FROM `$table`");
            while ($col = $colResult->fetch_assoc()) {
                $columns[] = $col['Field'];
            }
            
            while ($row = $result->fetch_assoc()) {
                $values = [];
                foreach ($columns as $col) {
                    $val = $row[$col];
                    if ($val === null) {
                        $values[] = 'NULL';
                    } else {
                        $values[] = "'" . $conn->real_escape_string($val) . "'";
                    }
                }
                $backup .= "INSERT INTO `$table` (`" . implode("`, `", $columns) . "`) VALUES (" . implode(", ", $values) . ");\n";
            }
            $backup .= "\n";
        }
        $result->close();
    }
    
    return $backup;
}

// Function to save backup to file
function saveBackup($conn) {
    $backupContent = createBackup($conn);
    $backupDir = __DIR__ . '/backups/';
    
    // Create backups directory if it doesn't exist
    if (!file_exists($backupDir)) {
        mkdir($backupDir, 0755, true);
    }
    
    $filename = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
    $filepath = $backupDir . $filename;
    
    file_put_contents($filepath, $backupContent);
    return $filepath;
}

// Function to clean old backups (keep last 30 days)
function cleanOldBackups() {
    $backupDir = __DIR__ . '/backups/';
    if (!file_exists($backupDir)) return;
    
    $files = glob($backupDir . '*.sql');
    $now = time();
    $thirtyDaysAgo = strtotime('-30 days');
    
    foreach ($files as $file) {
        if (filemtime($file) < $thirtyDaysAgo) {
            unlink($file);
        }
    }
}

// Function to send email using PHPMailer
function sendBackupEmail($to, $subject, $body, $attachmentContent, $filename, $clinicName) {
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->SMTPDebug = SMTP::DEBUG_OFF; // Enable for debugging: SMTP::DEBUG_SERVER
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com'; // Gmail SMTP server
        $mail->SMTPAuth   = true;
       $mail->Username   = 'santiagojhonny607@gmail.com';
        $mail->Password   = 'ndxl jqcy ghpt wocx';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        
        // Recipients
        $mail->setFrom('santiagojhonny607@gmail.com', $clinicName);
        $mail->addAddress($to);
        $mail->addReplyTo('santiagojhonny607@gmail.com', $clinicName);
        
        // Content
        $mail->isHTML(false);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        
        // Attachment
        $mail->addStringAttachment($attachmentContent, $filename);
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Mailer Error: " . $mail->ErrorInfo);
        return false;
    }
}

// Handle Download Backup
if (isset($_POST['action']) && $_POST['action'] === 'download') {
    $backupContent = createBackup($conn);
    $filename = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
    
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($backupContent));
    echo $backupContent;
    exit;
}

// Handle Email Backup
if (isset($_POST['action']) && $_POST['action'] === 'email') {
    $backupContent = createBackup($conn);
    $filename = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
    
    // Email settings - Change these to your email settings
    $to = 'admin@gmail.com'; // Change this to your admin email
    $subject = 'Database Backup - ' . $clinicName . ' - ' . date('Y-m-d');
    $body = "Dear Admin,\n\n";
    $body .= "Please find attached the database backup for " . $clinicName . ".\n";
    $body .= "Generated on: " . date('Y-m-d H:i:s') . "\n";
    $body .= "File: " . $filename . "\n\n";
    $body .= "This is an automated backup. Please store this file securely.\n\n";
    $body .= "Regards,\n" . $clinicName . " System";
    
    // Send email using PHPMailer
    $emailSent = sendBackupEmail($to, $subject, $body, $backupContent, $filename, $clinicName);
    
    if ($emailSent) {
        $message = 'Backup successfully sent to ' . $to;
        $messageType = 'success';
    } else {
        $message = 'Failed to send backup. Please check your email configuration.';
        $messageType = 'error';
    }
}

// Handle Save Backup (for scheduled backups)
if (isset($_POST['action']) && $_POST['action'] === 'save') {
    $filepath = saveBackup($conn);
    cleanOldBackups();
    
    if (file_exists($filepath)) {
        $message = 'Backup saved successfully to: ' . basename($filepath);
        $messageType = 'success';
    } else {
        $message = 'Failed to save backup.';
        $messageType = 'error';
    }
}

// Handle Schedule Backup
if (isset($_POST['action']) && $_POST['action'] === 'schedule') {
    $frequency = $_POST['frequency'] ?? 'daily';
    $scheduleFile = __DIR__ . '/schedule_config.php';
    
    // Create schedule configuration
    $config = "<?php\n";
    $config .= "// Scheduled backup configuration\n";
    $config .= "\$schedule_enabled = true;\n";
    $config .= "\$schedule_frequency = '" . $frequency . "';\n";
    $config .= "\$schedule_last_run = '" . date('Y-m-d H:i:s') . "';\n";
    $config .= "?>";
    
    file_put_contents($scheduleFile, $config);
    
    $message = 'Scheduled backup set to ' . ucfirst($frequency) . '.';
    $messageType = 'success';
}

// Handle Stop Schedule
if (isset($_POST['action']) && $_POST['action'] === 'stop_schedule') {
    $scheduleFile = __DIR__ . '/schedule_config.php';
    if (file_exists($scheduleFile)) {
        $config = "<?php\n";
        $config .= "// Scheduled backup configuration\n";
        $config .= "\$schedule_enabled = false;\n";
        $config .= "\$schedule_frequency = 'none';\n";
        $config .= "\$schedule_last_run = '" . date('Y-m-d H:i:s') . "';\n";
        $config .= "?>";
        file_put_contents($scheduleFile, $config);
        
        $message = 'Scheduled backups stopped successfully.';
        $messageType = 'success';
    }
}

// Check schedule configuration
$scheduleEnabled = false;
$scheduleFrequency = 'none';
$scheduleLastRun = 'Never';
$scheduleFile = __DIR__ . '/schedule_config.php';

if (file_exists($scheduleFile)) {
    include $scheduleFile;
    if (isset($schedule_enabled) && $schedule_enabled) {
        $scheduleEnabled = true;
        $scheduleFrequency = $schedule_frequency ?? 'daily';
        $scheduleLastRun = $schedule_last_run ?? 'Never';
    }
}

// Get backup history
$backupFiles = [];
$backupDir = __DIR__ . '/backups/';
if (file_exists($backupDir)) {
    $files = glob($backupDir . '*.sql');
    rsort($files); // Sort by newest first
    $backupFiles = array_slice($files, 0, 10); // Show only last 10 backups
}

// Handle checking scheduled backup (called by cron)
if (isset($_GET['cron']) && $_GET['cron'] === 'run') {
    $scheduleFile = __DIR__ . '/schedule_config.php';
    if (file_exists($scheduleFile)) {
        include $scheduleFile;
        if (isset($schedule_enabled) && $schedule_enabled) {
            saveBackup($conn);
            cleanOldBackups();
            
            // Update last run time
            $config = "<?php\n";
            $config .= "// Scheduled backup configuration\n";
            $config .= "\$schedule_enabled = true;\n";
            $config .= "\$schedule_frequency = '" . $schedule_frequency . "';\n";
            $config .= "\$schedule_last_run = '" . date('Y-m-d H:i:s') . "';\n";
            $config .= "?>";
            file_put_contents($scheduleFile, $config);
            
            echo "Scheduled backup completed successfully.";
        } else {
            echo "Scheduled backups are disabled.";
        }
    } else {
        echo "No schedule configuration found.";
    }
    exit;
}

// Get last backup time from saved files
$lastBackupTime = 'Never';
if (!empty($backupFiles)) {
    $latestFile = $backupFiles[0];
    $lastBackupTime = date('F d, Y h:i A', filemtime($latestFile));
}

include 'sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Database Backup · <?= h($clinicName) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700;800;900&family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
* { font-family: 'DM Sans', system-ui, sans-serif; box-sizing: border-box; }
body {
  margin: 0;
  background: #f8fafc;
  background-image: linear-gradient(rgba(11,22,40,0.04) 1px, transparent 1px),
                    linear-gradient(90deg, rgba(11,22,40,0.04) 1px, transparent 1px);
  background-size: 32px 32px;
  background-position: -1px -1px;
  color: #111827;
}
h1, h2, h3, h4, .logo-name { font-family: 'Playfair Display', Georgia, serif; }
:root {
  --navy: #0b1628;
  --teal: #0d9e8c;
  --teal-dark: #0a7d6e;
  --teal-light: #e6f7f5;
  --slate: #64748b;
  --shadow-card: 0 2px 8px rgba(11,22,40,.06), 0 12px 32px rgba(11,22,40,.08);
  --line: #eef1f6;
}
a { text-decoration: none; }
.text-teal { color: var(--teal); }

@media (min-width: 1024px) { .main-content { margin-left: 270px; } }
@media (max-width: 1023px) { .main-content { margin-left: 0; } }

.dashboard-wrapper { max-width: 1360px; margin: 0 auto; padding: 28px 28px 48px; }

/* top bar */
.top-bar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 28px; }
.top-bar h1 { font-size: 26px; font-weight: 700; color: var(--navy); margin: 0; }
.top-bar .sub { font-size: 14px; color: var(--slate); margin-top: 2px; }
.top-bar-actions { display: flex; gap: 10px; flex-wrap: wrap; }
.btn-primary {
  background: var(--teal); color: #fff; padding: 10px 22px; border-radius: 12px;
  font-weight: 600; font-size: 13px; border: none; cursor: pointer;
  display: inline-flex; align-items: center; gap: 8px;
  box-shadow: 0 4px 12px rgba(13,158,140,0.25); transition: all .2s;
}
.btn-primary:hover { background: var(--teal-dark); transform: translateY(-2px); box-shadow: 0 8px 22px rgba(13,158,140,0.35); }
.btn-primary i { font-size: 14px; }
.btn-outline {
  background: #fff; color: var(--slate); padding: 8px 18px; border-radius: 10px;
  border: 1.5px solid #e2e8f0; font-weight: 600; font-size: 13px; cursor: pointer;
  transition: all .2s; display: inline-flex; align-items: center; gap: 6px;
}
.btn-outline:hover { background: #f8fafc; border-color: #cbd5e1; }
.btn-secondary {
  background: #fff; color: var(--navy); padding: 10px 22px; border-radius: 12px;
  font-weight: 600; font-size: 13px; border: 1.5px solid var(--line); cursor: pointer;
  display: inline-flex; align-items: center; gap: 8px;
  transition: all .2s;
}
.btn-secondary:hover { background: #f8fafc; border-color: #cbd5e1; transform: translateY(-2px); }
.btn-danger {
  background: #dc3545; color: #fff; padding: 10px 22px; border-radius: 12px;
  font-weight: 600; font-size: 13px; border: none; cursor: pointer;
  display: inline-flex; align-items: center; gap: 8px;
  box-shadow: 0 4px 12px rgba(220,53,69,0.25); transition: all .2s;
}
.btn-danger:hover { background: #c82333; transform: translateY(-2px); box-shadow: 0 8px 22px rgba(220,53,69,0.35); }
.btn-success {
  background: #28a745; color: #fff; padding: 10px 22px; border-radius: 12px;
  font-weight: 600; font-size: 13px; border: none; cursor: pointer;
  display: inline-flex; align-items: center; gap: 8px;
  box-shadow: 0 4px 12px rgba(40,167,69,0.25); transition: all .2s;
}
.btn-success:hover { background: #218838; transform: translateY(-2px); box-shadow: 0 8px 22px rgba(40,167,69,0.35); }

/* cards */
.card { background: #fff; border-radius: 20px; box-shadow: var(--shadow-card); border: 1px solid var(--line); overflow: hidden; margin-bottom: 24px; }
.card-header { padding: 18px 24px; border-bottom: 1px solid var(--line); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; background: #fafbfc; }
.card-header h2 { font-size: 15px; font-weight: 700; color: var(--navy); display: flex; align-items: center; gap: 10px; margin: 0; }
.card-header h2 i { color: var(--teal); }
.card-header .badge {
  font-size: 11px; padding: 4px 12px; border-radius: 50px; font-weight: 600;
}
.badge-success { background: #d4edda; color: #155724; }
.badge-warning { background: #fef3d7; color: #b45309; }
.badge-danger { background: #f8d7da; color: #721c24; }
.card-body { padding: 24px; }
.card-body table { width: 100%; border-collapse: collapse; }
.card-body th { text-align: left; padding: 8px 12px; font-size: 11px; text-transform: uppercase; color: var(--slate); border-bottom: 2px solid var(--line); }
.card-body td { padding: 10px 12px; border-bottom: 1px solid var(--line); font-size: 13px; }
.card-body tr:hover td { background: #fafbfc; }

/* alert messages */
.alert {
  padding: 14px 20px;
  border-radius: 12px;
  margin-bottom: 20px;
  font-weight: 500;
  display: flex;
  align-items: center;
  gap: 10px;
}
.alert-success {
  background: #d4edda;
  color: #155724;
  border: 1px solid #c3e6cb;
}
.alert-error {
  background: #f8d7da;
  color: #721c24;
  border: 1px solid #f5c6cb;
}
.alert i { font-size: 18px; }

/* backup options grid */
.backup-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
  gap: 20px;
}
.backup-option {
  background: #fafbfc;
  border: 2px solid var(--line);
  border-radius: 16px;
  padding: 24px 20px;
  text-align: center;
  transition: all .2s;
}
.backup-option:hover {
  border-color: var(--teal);
  transform: translateY(-4px);
  box-shadow: 0 8px 24px rgba(11,22,40,.08);
}
.backup-option .icon {
  font-size: 42px;
  color: var(--teal);
  margin-bottom: 12px;
}
.backup-option .icon.warning { color: #ffc107; }
.backup-option h3 {
  font-family: 'DM Sans', sans-serif;
  font-size: 16px;
  font-weight: 700;
  color: var(--navy);
  margin: 0 0 8px 0;
}
.backup-option p {
  font-size: 13px;
  color: var(--slate);
  margin: 0 0 16px 0;
  line-height: 1.5;
}
.backup-option .btn-action {
  padding: 8px 20px;
  border-radius: 8px;
  font-weight: 600;
  font-size: 13px;
  border: none;
  cursor: pointer;
  transition: all .2s;
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
.btn-download {
  background: var(--teal);
  color: #fff;
}
.btn-download:hover { background: var(--teal-dark); }
.btn-email {
  background: #0d6efd;
  color: #fff;
}
.btn-email:hover { background: #0b5ed7; }
.btn-schedule {
  background: #6f42c1;
  color: #fff;
}
.btn-schedule:hover { background: #5a32a3; }

/* info rows */
.info-row {
  display: flex;
  justify-content: space-between;
  padding: 12px 0;
  border-bottom: 1px solid var(--line);
  font-size: 14px;
}
.info-row:last-child { border-bottom: none; }
.info-label { color: var(--slate); font-weight: 500; }
.info-value { font-weight: 600; color: var(--navy); }

/* schedule form */
.schedule-form {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  align-items: center;
  margin-top: 8px;
}
.schedule-form select {
  padding: 8px 16px;
  border: 1.5px solid var(--line);
  border-radius: 8px;
  font-weight: 500;
  font-size: 13px;
  background: #fff;
  color: var(--navy);
  cursor: pointer;
}
.schedule-form select:focus {
  outline: none;
  border-color: var(--teal);
}

/* cron info */
.cron-box {
  background: #f8fafc;
  border: 1px solid var(--line);
  border-radius: 8px;
  padding: 12px 16px;
  margin-top: 10px;
  font-size: 12px;
  color: var(--slate);
  font-family: 'Courier New', monospace;
  word-break: break-all;
}

/* email config box */
.email-config-box {
  background: #fef3d7;
  border: 1px solid #f6d7a0;
  border-radius: 8px;
  padding: 14px 18px;
  margin-top: 10px;
  font-size: 13px;
  color: #856404;
}
.email-config-box code {
  background: #fff;
  padding: 2px 8px;
  border-radius: 4px;
  font-size: 12px;
}

/* footer */
.footer-note { text-align: center; font-size: 12px; color: #9aa4b2; margin-top: 24px; }

/* responsive */
@media (max-width: 640px) {
  .top-bar h1 { font-size: 22px; }
  .backup-grid { grid-template-columns: 1fr; }
  .card-body { padding: 16px; }
  .card-body table { font-size: 12px; }
  .schedule-form { flex-direction: column; align-items: stretch; }
}
</style>
</head>
<body>

<div class="main-content">
<div class="dashboard-wrapper">

  <!-- TOP BAR -->
  <div class="top-bar">
    <div>
      <h1>Database Backup</h1>
      <div class="sub"><?= h($clinicName) ?> · Secure database exports</div>
    </div>
    <div class="top-bar-actions">
      <button class="btn-outline" onclick="window.location.reload()"><i class="fas fa-sync-alt"></i> Refresh</button>
    </div>
  </div>

  <!-- Alert Messages -->
  <?php if ($message): ?>
    <div class="alert alert-<?= $messageType === 'success' ? 'success' : 'error' ?>">
      <i class="fas <?= $messageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
      <?= h($message) ?>
    </div>
  <?php endif; ?>

  

  <!-- Backup Information -->
  <div class="card">
    <div class="card-header">
      <h2><i class="fas fa-info-circle"></i> Backup Information</h2>
      <?php if ($scheduleEnabled): ?>
        <span class="badge badge-success"><i class="fas fa-clock"></i> Scheduled: <?= ucfirst($scheduleFrequency) ?></span>
      <?php else: ?>
        <span class="badge badge-warning"><i class="fas fa-pause"></i> Not Scheduled</span>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <div class="info-row">
        <span class="info-label"><i class="fas fa-database" style="color:var(--teal);"></i> Database Name</span>
        <span class="info-value"><?= h($conn->query("SELECT DATABASE()")->fetch_row()[0]) ?></span>
      </div>
      <div class="info-row">
        <span class="info-label"><i class="fas fa-clock" style="color:var(--teal);"></i> Last Backup</span>
        <span class="info-value"><?= h($lastBackupTime) ?></span>
      </div>
      <div class="info-row">
        <span class="info-label"><i class="fas fa-file-alt" style="color:var(--teal);"></i> Backup Format</span>
        <span class="info-value">SQL (MySQL Dump)</span>
      </div>
      <div class="info-row">
        <span class="info-label"><i class="fas fa-shield-alt" style="color:var(--teal);"></i> Security</span>
        <span class="info-value">🔒 Secure download & storage</span>
      </div>
      <?php if ($scheduleEnabled): ?>
      <div class="info-row">
        <span class="info-label"><i class="fas fa-calendar-alt" style="color:var(--teal);"></i> Schedule</span>
        <span class="info-value">Runs <?= ucfirst($scheduleFrequency) ?> · Last run: <?= h($scheduleLastRun) ?></span>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Backup Options -->
  <div class="card">
    <div class="card-header">
      <h2><i class="fas fa-download"></i> Backup Actions</h2>
    </div>
    <div class="card-body">
      <div class="backup-grid">
        
        <!-- Option 1: Download -->
        <div class="backup-option">
          <div class="icon"><i class="fas fa-download"></i></div>
          <h3>Download Backup</h3>
          <p>Download a complete SQL backup of your database. This will save all tables, structure, and data.</p>
          <form method="POST" action="">
            <input type="hidden" name="action" value="download">
            <button type="submit" class="btn-action btn-download">
              <i class="fas fa-download"></i> Download Now
            </button>
          </form>
        </div>

        <!-- Option 2: Email -->
        <div class="backup-option">
          <div class="icon"><i class="fas fa-envelope"></i></div>
          <h3>Email Backup</h3>
          <p>Send the backup directly to the admin email address. Perfect for off-site storage.</p>
          <form method="POST" action="">
            <input type="hidden" name="action" value="email">
            <button type="submit" class="btn-action btn-email">
              <i class="fas fa-envelope"></i> Email to Admin
            </button>
          </form>
          <div style="margin-top: 8px; font-size: 11px; color: var(--slate);">
            <i class="fas fa-info-circle"></i> Sends to: admin@gmail.com
          </div>
        </div>

        <!-- Option 3: Save to Server -->
        <div class="backup-option">
          <div class="icon" style="color: #28a745;"><i class="fas fa-save"></i></div>
          <h3>Save to Server</h3>
          <p>Save a backup file on the server. Files are stored in the backups/ folder and auto-cleaned after 30 days.</p>
          <form method="POST" action="">
            <input type="hidden" name="action" value="save">
            <button type="submit" class="btn-action btn-success">
              <i class="fas fa-save"></i> Save Backup
            </button>
          </form>
        </div>

        <!-- Option 4: Schedule -->
        <div class="backup-option" style="border-color: <?= $scheduleEnabled ? 'var(--teal)' : 'var(--line)' ?>;">
          <div class="icon <?= $scheduleEnabled ? '' : 'warning' ?>">
            <i class="fas <?= $scheduleEnabled ? 'fa-clock' : 'fa-clock' ?>"></i>
          </div>
          <h3><?= $scheduleEnabled ? 'Scheduled Backups' : 'Schedule Backups' ?></h3>
          <p>Set up automatic daily, weekly, or monthly backups. Never forget to backup again!</p>
          
          <?php if ($scheduleEnabled): ?>
            <form method="POST" action="">
              <input type="hidden" name="action" value="stop_schedule">
              <button type="submit" class="btn-action btn-danger">
                <i class="fas fa-stop"></i> Stop Schedule
              </button>
            </form>
            <div style="margin-top: 8px; font-size: 11px; color: var(--teal);">
              <i class="fas fa-check-circle"></i> Running <?= ucfirst($scheduleFrequency) ?>
            </div>
          <?php else: ?>
            <form method="POST" action="" class="schedule-form">
              <input type="hidden" name="action" value="schedule">
              <select name="frequency" required>
                <option value="daily">Daily</option>
                <option value="weekly">Weekly</option>
                <option value="monthly">Monthly</option>
              </select>
              <button type="submit" class="btn-action btn-schedule">
                <i class="fas fa-play"></i> Start Schedule
              </button>
            </form>
          <?php endif; ?>
        </div>

      </div>
    </div>
  </div>

  <!-- Cron Setup Card -->
  <div class="card">
    <div class="card-header">
      <h2><i class="fas fa-terminal"></i> Cron Job Setup (For Scheduled Backups)</h2>
    </div>
    <div class="card-body">
      <p style="color: var(--slate); font-size: 13px; margin-top: 0;">
        To enable automatic scheduled backups, add the following cron job to your server:
      </p>
      <div class="cron-box">
        <?php 
        $cronPath = __DIR__ . '/backup.php?cron=run';
        $cronCmd = "wget -q -O /dev/null " . (isset($_SERVER['HTTP_HOST']) ? 'http://' . $_SERVER['HTTP_HOST'] . str_replace($_SERVER['DOCUMENT_ROOT'], '', $cronPath) : $cronPath);
        ?>
        # For Daily backups (runs at 2am every day)
        0 2 * * *  
        
        # For Weekly backups (runs at 2am every Sunday)
        # 0 2 * * 0  
        
        # For Monthly backups (runs at 2am on the 1st of every month)
        # 0 2 1 * * 
      </div>
      <p style="color: var(--slate); font-size: 12px; margin-top: 10px;">
        <i class="fas fa-info-circle"></i> 
        Replace the command above with your actual server path. If you don't have access to cron, you can use a web-based cron service.
      </p>
    </div>
  </div>

  <!-- Backup History -->
  <?php if (!empty($backupFiles)): ?>
  <div class="card">
    <div class="card-header">
      <h2><i class="fas fa-history"></i> Recent Backups</h2>
      <span class="badge badge-success"><?= count($backupFiles) ?> files</span>
    </div>
    <div class="card-body" style="padding: 0; overflow-x: auto;">
      <table>
        <thead>
          <tr>
            <th>Filename</th>
            <th>Date</th>
            <th>Size</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($backupFiles as $file): ?>
          <tr>
            <td><?= h(basename($file)) ?></td>
            <td><?= date('F d, Y h:i A', filemtime($file)) ?></td>
            <td><?= number_format(filesize($file) / 1024, 2) ?> KB</td>
            <td>
              <a href="../../api/download_backup.php?file=<?= urlencode(basename($file)) ?>" class="btn-download" style="padding: 4px 12px; border-radius: 6px; color: #fff; font-size: 11px; display: inline-block; text-decoration: none; background: var(--teal);">
                <i class="fas fa-download"></i>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <!-- Footer -->
  <div class="footer-note">
    <i class="fas fa-tooth" style="color: var(--teal); opacity:.5; margin-right:4px;"></i> <?= h($clinicName) ?> · Admin Dashboard
  </div>

</div>
</div>

<script>
  // Auto-dismiss alerts after 5 seconds
  setTimeout(function() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(alert) {
      alert.style.transition = 'opacity 0.5s';
      alert.style.opacity = '0';
      setTimeout(function() {
        alert.remove();
      }, 500);
    });
  }, 5000);
</script>

</body>
</html>