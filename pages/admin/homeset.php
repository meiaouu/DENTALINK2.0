<?php
require '../../conn/conn.php';
error_reporting(E_ALL & ~E_NOTICE);
function h($value) { return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8'); }

$errors = [];
$success = '';

// Handle image upload
function uploadImage($file, $folder = 'uploads') {
    $targetDir = __DIR__ . '/../../' . $folder . '/';
    if (!file_exists($targetDir)) {
        mkdir($targetDir, 0777, true);
    }
    
    $fileName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', basename($file['name']));
    $targetFile = $targetDir . $fileName;
    $fileType = strtolower(pathinfo($targetFile, PATHINFO_EXTENSION));
    $allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
    
    if (!in_array($fileType, $allowedTypes)) {
        throw new Exception('Invalid file type. Allowed: JPG, PNG, GIF, WEBP, SVG');
    }
    
    if ($file['size'] > 5000000) {
        throw new Exception('File size too large. Max 5MB');
    }
    
    if (move_uploaded_file($file['tmp_name'], $targetFile)) {
        return $folder . '/' . $fileName;
    }
    throw new Exception('Failed to upload image');
}

// Delete image file
function deleteImage($path) {
    if ($path && file_exists(__DIR__ . '/../../' . $path)) {
        unlink(__DIR__ . '/../../' . $path);
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'update_settings') {
            $result = $conn->query("SELECT * FROM clinic_settings WHERE id = 1");
            $currentSettings = $result->fetch_assoc();
            $hero_background = $currentSettings['hero_background'] ?? '';
            
            if (isset($_FILES['hero_background']) && $_FILES['hero_background']['error'] === UPLOAD_ERR_OK) {
                $hero_background = uploadImage($_FILES['hero_background'], 'uploads/hero');
                if (!empty($currentSettings['hero_background']) && file_exists(__DIR__ . '/../../' . $currentSettings['hero_background'])) {
                    unlink(__DIR__ . '/../../' . $currentSettings['hero_background']);
                }
            }
            
            if (isset($_POST['remove_hero_bg']) && $_POST['remove_hero_bg'] == '1') {
                if (!empty($currentSettings['hero_background']) && file_exists(__DIR__ . '/../../' . $currentSettings['hero_background'])) {
                    unlink(__DIR__ . '/../../' . $currentSettings['hero_background']);
                }
                $hero_background = '';
            }

            $stmt = $conn->prepare("UPDATE clinic_settings SET
                clinic_name = ?, clinic_tagline = ?, phone = ?, email = ?, location = ?, clinic_hours = ?,
                facebook_url = ?, instagram_url = ?, messenger_url = ?,
                hero_badge = ?, hero_title_line1 = ?, hero_title_line2 = ?, hero_subtitle = ?, hero_description = ?,
                hero_background = ?,
                stat1_value = ?, stat1_label = ?, stat2_value = ?, stat2_label = ?, stat3_value = ?, stat3_label = ?,
                about_heading = ?, about_description = ?, cta_heading = ?, cta_description = ?
                WHERE id = 1");
            $stmt->bind_param(
                "sssssssssssssssssssssssss",
                trim($_POST['clinic_name']), trim($_POST['clinic_tagline']), trim($_POST['phone']),
                trim($_POST['email']), trim($_POST['location']), trim($_POST['clinic_hours']),
                trim($_POST['facebook_url']), trim($_POST['instagram_url']), trim($_POST['messenger_url']),
                trim($_POST['hero_badge']), trim($_POST['hero_title_line1']), trim($_POST['hero_title_line2']),
                trim($_POST['hero_subtitle']), trim($_POST['hero_description']),
                $hero_background,
                trim($_POST['stat1_value']), trim($_POST['stat1_label']),
                trim($_POST['stat2_value']), trim($_POST['stat2_label']),
                trim($_POST['stat3_value']), trim($_POST['stat3_label']),
                trim($_POST['about_heading']), trim($_POST['about_description']),
                trim($_POST['cta_heading']), trim($_POST['cta_description'])
            );
            $stmt->execute();
            $success = 'Homepage settings saved successfully!';
        }

      if ($action === 'save_service') {
    $id       = $_POST['id'] ?? '';
    $title    = trim($_POST['title']);
    $desc     = trim($_POST['description']);
    $duration = trim($_POST['duration']) ?: '30 min';
    $price    = (float)($_POST['price'] ?? 0);
    $order    = (int)($_POST['sort_order'] ?? 0);
    $active   = isset($_POST['is_active']) ? 1 : 0;
    $image    = '';

    if ($title === '') { throw new Exception('Service title is required.'); }

    // Check if a new image was uploaded
    $hasNewImage = isset($_FILES['service_image']) && $_FILES['service_image']['error'] === UPLOAD_ERR_OK;
    
    if ($hasNewImage) {
        try {
            // Upload the image
            $image = uploadImage($_FILES['service_image'], 'uploads/services');
            
            // Debug: Log the uploaded image path
            error_log("New image uploaded: " . $image);
            
        } catch (Exception $e) {
            error_log("Image upload error: " . $e->getMessage());
            throw new Exception('Failed to upload image: ' . $e->getMessage());
        }
    } else {
        // Debug: Log that no new image was uploaded
        error_log("No new image uploaded. File error: " . ($_FILES['service_image']['error'] ?? 'No file'));
    }

    if ($id !== '') {
        // EDITING EXISTING SERVICE
        if ($hasNewImage && !empty($image)) {
            // Get and delete old image
            $stmt = $conn->prepare("SELECT image FROM services WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_row();
            $oldImage = $row[0] ?? null;
            if ($oldImage) {
                deleteImage($oldImage);
                error_log("Old image deleted: " . $oldImage);
            }
            
            // Update with new image
            // FIX: Correct type string - 7 placeholders in query, 7 variables
            // title(s), description(s), duration(s), price(d), order(i), active(i), image(s), id(i)
            $stmt = $conn->prepare("UPDATE services SET title=?, description=?, duration=?, price=?, sort_order=?, is_active=?, image=? WHERE id=?");
$stmt->bind_param("sssdiisi", $title, $desc, $duration, $price, $order, $active, $image, $id);
            $stmt->execute();
            
            // Debug: Check if update worked
            error_log("Service updated with new image. ID: $id, Image: $image, Rows affected: " . $stmt->affected_rows);
            
        } else {
            // Update WITHOUT changing image
            $stmt = $conn->prepare("UPDATE services SET title=?, description=?, duration=?, price=?, sort_order=?, is_active=? WHERE id=?");
            $stmt->bind_param("sssdisi", $title, $desc, $duration, $price, $order, $active, $id);
            $stmt->execute();
            
            // Debug: Log update without image
            error_log("Service updated without image change. ID: $id, Rows affected: " . $stmt->affected_rows);
        }
        $success = 'Service updated successfully!';
        
    } else {
        // ADDING NEW SERVICE
        if (empty($image)) {
            error_log("Warning: New service added without image");
        }
        $stmt = $conn->prepare("INSERT INTO services (title, description, duration, price, sort_order, is_active, image) VALUES (?,?,?,?,?,?,?)");
        $stmt->bind_param("sssdisi", $title, $desc, $duration, $price, $order, $active, $image);
        $stmt->execute();
        $success = 'Service added successfully!';
        error_log("New service added. ID: " . $conn->insert_id . ", Image: " . $image);
    }
}

        if ($action === 'delete_service') {
            $stmt = $conn->prepare("SELECT image FROM services WHERE id = ?");
            $stmt->bind_param("i", $_POST['id']);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_row();
            $image = $row[0] ?? null;
            if ($image) deleteImage($image);
            
            $stmt = $conn->prepare("DELETE FROM services WHERE id = ?");
            $stmt->bind_param("i", $_POST['id']);
            $stmt->execute();
            $success = 'Service deleted successfully!';
        }

        if ($action === 'save_feature') {
            $id     = $_POST['id'] ?? '';
            $title  = trim($_POST['title']);
            $desc   = trim($_POST['description']);
            $order  = (int)($_POST['sort_order'] ?? 0);
            $active = isset($_POST['is_active']) ? 1 : 0;
            $image  = '';

            if ($title === '') { throw new Exception('Feature title is required.'); }

            if (isset($_FILES['feature_image']) && $_FILES['feature_image']['error'] === UPLOAD_ERR_OK) {
                $image = uploadImage($_FILES['feature_image'], 'uploads/features');
            }

            if ($id !== '') {
                if ($image) {
                    $stmt = $conn->prepare("SELECT image FROM features WHERE id = ?");
                    $stmt->bind_param("i", $id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $row = $result->fetch_row();
                    $oldImage = $row[0] ?? null;
                    if ($oldImage) deleteImage($oldImage);
                    
                    $stmt = $conn->prepare("UPDATE features SET title=?, description=?, sort_order=?, is_active=?, image=? WHERE id=?");
                    $stmt->bind_param("sssisi", $title, $desc, $order, $active, $image, $id);
                    $stmt->execute();
                } else {
                    $stmt = $conn->prepare("UPDATE features SET title=?, description=?, sort_order=?, is_active=? WHERE id=?");
                    $stmt->bind_param("sssii", $title, $desc, $order, $active, $id);
                    $stmt->execute();
                }
                $success = 'Feature updated successfully!';
            } else {
                $stmt = $conn->prepare("INSERT INTO features (title, description, sort_order, is_active, image) VALUES (?,?,?,?,?)");
                $stmt->bind_param("sssis", $title, $desc, $order, $active, $image);
                $stmt->execute();
                $success = 'Feature added successfully!';
            }
        }

        if ($action === 'delete_feature') {
            $stmt = $conn->prepare("SELECT image FROM features WHERE id = ?");
            $stmt->bind_param("i", $_POST['id']);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_row();
            $image = $row[0] ?? null;
            if ($image) deleteImage($image);
            
            $stmt = $conn->prepare("DELETE FROM features WHERE id = ?");
            $stmt->bind_param("i", $_POST['id']);
            $stmt->execute();
            $success = 'Feature deleted successfully!';
        }

        if ($action === 'save_gallery') {
            $id     = $_POST['id'] ?? '';
            $tag    = trim($_POST['tag']);
            $order  = (int)($_POST['sort_order'] ?? 0);
            $active = isset($_POST['is_active']) ? 1 : 0;
            $image  = '';

            if ($tag === '') { throw new Exception('Gallery label is required.'); }

            if (isset($_FILES['gallery_image']) && $_FILES['gallery_image']['error'] === UPLOAD_ERR_OK) {
                $image = uploadImage($_FILES['gallery_image'], 'uploads/gallery');
            }

            if ($id !== '') {
                if ($image) {
                    $stmt = $conn->prepare("SELECT image FROM gallery_items WHERE id = ?");
                    $stmt->bind_param("i", $id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $row = $result->fetch_row();
                    $oldImage = $row[0] ?? null;
                    if ($oldImage) deleteImage($oldImage);
                    
                    $stmt = $conn->prepare("UPDATE gallery_items SET tag=?, sort_order=?, is_active=?, image=? WHERE id=?");
                    $stmt->bind_param("ssisi", $tag, $order, $active, $image, $id);
                    $stmt->execute();
                } else {
                    $stmt = $conn->prepare("UPDATE gallery_items SET tag=?, sort_order=?, is_active=? WHERE id=?");
                    $stmt->bind_param("ssii", $tag, $order, $active, $id);
                    $stmt->execute();
                }
                $success = 'Gallery item updated successfully!';
            } else {
                $stmt = $conn->prepare("INSERT INTO gallery_items (tag, sort_order, is_active, image) VALUES (?,?,?,?)");
                $stmt->bind_param("ssis", $tag, $order, $active, $image);
                $stmt->execute();
                $success = 'Gallery item added successfully!';
            }
        }

        if ($action === 'delete_gallery') {
            $stmt = $conn->prepare("SELECT image FROM gallery_items WHERE id = ?");
            $stmt->bind_param("i", $_POST['id']);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_row();
            $image = $row[0] ?? null;
            if ($image) deleteImage($image);
            
            $stmt = $conn->prepare("DELETE FROM gallery_items WHERE id = ?");
            $stmt->bind_param("i", $_POST['id']);
            $stmt->execute();
            $success = 'Gallery item deleted successfully!';
        }



        // ====================================================================
// ANNOUNCEMENTS
// ====================================================================
if ($action === 'save_announcement') {
    $id       = $_POST['id'] ?? '';
    $title    = trim($_POST['title'] ?? '');
    $message  = trim($_POST['message'] ?? '');
    $cta_text = trim($_POST['cta_text'] ?? '');
$cta_link = trim($_POST['cta_link'] ?? '');
if ($cta_link === '__custom__') {
    $cta_link = trim($_POST['cta_link_custom'] ?? '');
}
    $type     = $_POST['type'] ?? 'info';
    $icon     = trim($_POST['icon'] ?? 'fa-bullhorn');
    $layout   = $_POST['layout'] ?? 'banner';
    $order    = (int)($_POST['sort_order'] ?? 0);
    $active   = isset($_POST['is_active']) ? 1 : 0;
    $dismiss  = isset($_POST['is_dismissible']) ? 1 : 0;
    $start    = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
    $end      = !empty($_POST['end_date'])   ? $_POST['end_date']   : null;
    $image    = '';

    if ($title === '')   { throw new Exception('Announcement title is required.'); }
    if ($message === '') { throw new Exception('Announcement message is required.'); }

    // Validate type & layout against allowed values
    $allowedTypes = ['info', 'success', 'warning', 'promo'];
    if (!in_array($type, $allowedTypes)) $type = 'info';

    $allowedLayouts = ['banner', 'floating', 'inline'];
    if (!in_array($layout, $allowedLayouts)) $layout = 'banner';

    if (isset($_FILES['announcement_image']) && $_FILES['announcement_image']['error'] === UPLOAD_ERR_OK) {
        $image = uploadImage($_FILES['announcement_image'], 'uploads/announcements');
    }

    if ($id !== '') {
        // EDIT
              if ($image) {
            // Delete old image
            $stmt = $conn->prepare("SELECT image FROM announcements WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_row();
            if (!empty($row[0])) deleteImage($row[0]);

            $stmt = $conn->prepare("UPDATE announcements SET
                title=?, message=?, image=?, cta_text=?, cta_link=?,
                type=?, icon=?, layout=?, is_active=?, is_dismissible=?,
                start_date=?, end_date=?, sort_order=?
                WHERE id=?");
            // 14 placeholders total:
            //  s=title s=message s=image s=cta_text s=cta_link
            //  s=type  s=icon    s=layout i=active   i=dismiss
            //  s=start s=end     i=order  i=id
            //  → "ssssssssiissii"
            $stmt->bind_param(
                "ssssssssiissii",
                $title, $message, $image, $cta_text, $cta_link,
                $type, $icon, $layout, $active, $dismiss,
                $start, $end, $order, $id
            );
            $stmt->execute();
             } else {
            $stmt = $conn->prepare("UPDATE announcements SET
                title=?, message=?, cta_text=?, cta_link=?,
                type=?, icon=?, layout=?, is_active=?, is_dismissible=?,
                start_date=?, end_date=?, sort_order=?
                WHERE id=?");
            // 13 placeholders total (no image column):
            //  s=title s=message s=cta_text s=cta_link
            //  s=type  s=icon    s=layout   i=active  i=dismiss
            //  s=start s=end     i=order    i=id
            //  → "sssssssiissii"
            $stmt->bind_param(
                "sssssssiissii",
                $title, $message, $cta_text, $cta_link,
                $type, $icon, $layout, $active, $dismiss,
                $start, $end, $order, $id
            );
            $stmt->execute();
        }
        $success = 'Announcement updated successfully!';
       } else {
        // ADD
        $stmt = $conn->prepare("INSERT INTO announcements
            (title, message, image, cta_text, cta_link, type, icon, layout,
             is_active, is_dismissible, start_date, end_date, sort_order)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
        // Types: s=title, s=message, s=image, s=cta_text, s=cta_link,
        //        s=type, s=icon, s=layout, i=active, i=dismiss,
        //        s=start, s=end, i=order  →  13 chars total
        $stmt->bind_param(
            "ssssssssiissi",
            $title, $message, $image, $cta_text, $cta_link,
            $type, $icon, $layout, $active, $dismiss,
            $start, $end, $order
        );
        $stmt->execute();
        $success = 'Announcement added successfully!';
    }
}

if ($action === 'delete_announcement') {
    $stmt = $conn->prepare("SELECT image FROM announcements WHERE id = ?");
    $stmt->bind_param("i", $_POST['id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    if (!empty($row[0])) deleteImage($row[0]);

    $stmt = $conn->prepare("DELETE FROM announcements WHERE id = ?");
    $stmt->bind_param("i", $_POST['id']);
    $stmt->execute();
    $success = 'Announcement deleted successfully!';
}
    } catch (Exception $e) {
        $errors[] = $e->getMessage();
    }
}

$settings = $conn->query("SELECT * FROM clinic_settings WHERE id = 1")->fetch_assoc();
$services = $conn->query("SELECT * FROM services ORDER BY sort_order ASC")->fetch_all(MYSQLI_ASSOC);
$features = $conn->query("SELECT * FROM features ORDER BY sort_order ASC")->fetch_all(MYSQLI_ASSOC);
$gallery  = $conn->query("SELECT * FROM gallery_items ORDER BY sort_order ASC")->fetch_all(MYSQLI_ASSOC);

$announcements = $conn->query("SELECT * FROM announcements ORDER BY sort_order ASC, id DESC")->fetch_all(MYSQLI_ASSOC);

// Helper: figure out if an announcement is scheduled, expired, or live
function announcementStatus($a) {
    $today = date('Y-m-d');
    if (!$a['is_active']) return ['label' => 'Hidden',    'class' => 'status-hidden'];
    if (!empty($a['start_date']) && $a['start_date'] > $today) return ['label' => 'Scheduled', 'class' => 'status-scheduled'];
    if (!empty($a['end_date'])   && $a['end_date']   < $today) return ['label' => 'Expired',   'class' => 'status-expired'];
    return ['label' => 'Live', 'class' => 'status-active'];
}

include "sidebar.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Home Settings · <?= h($settings['clinic_name']) ?></title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700;800;900&family=DM+Sans:wght@400;500;600;700;800&display=swap');
    * { font-family: 'DM Sans', system-ui, sans-serif; }
    h1, h2, h3, h4, .logo-name { font-family: 'Playfair Display', Georgia, serif; }
    :root {
      --navy: #0b1628;
      --teal: #0d9e8c;
      --teal-dark: #0a7d6e;
      --teal-light: #e6f7f5;
      --off: #f7f9fc;
      --slate: #64748b;
      --shadow-card: 0 2px 8px rgba(11,22,40,.06), 0 12px 32px rgba(11,22,40,.08);
    }
    body {
    background-color: #f8fafc;
    background-image:
        linear-gradient(rgba(11,22,40,0.04) 1px, transparent 1px),
        linear-gradient(90deg, rgba(11,22,40,0.04) 1px, transparent 1px);
    background-size: 32px 32px;
    background-position: -1px -1px;
}
    .bg-teal { background-color: var(--teal); }
    .text-teal { color: var(--teal); }
    .bg-dark-blue { background-color: var(--navy); }
    .text-dark-blue { color: var(--navy); }
    .bg-light-teal { background-color: var(--teal-light); }
    .bg-soft-gray { background-color: var(--off); }

    .container-modern { max-width: 1280px; margin: 0 auto; padding: 0 1.5rem; }
    @media (max-width: 640px) { .container-modern { padding: 0 1rem; } }

    .btn-primary {
      background: var(--teal);
      color: white;
      padding: 10px 22px;
      border-radius: 10px;
      font-weight: 600;
      font-size: 13px;
      transition: all 0.2s;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      box-shadow: 0 4px 14px rgba(13,158,140,0.25);
      border: none;
      cursor: pointer;
      text-decoration: none;
    }
    .btn-primary:hover { background: #0a7d6e; transform: translateY(-2px); box-shadow: 0 10px 28px rgba(13,158,140,0.35); }
    .btn-outline {
      border: 1.5px solid var(--teal);
      color: var(--teal);
      padding: 8px 18px;
      border-radius: 10px;
      font-weight: 600;
      font-size: 12px;
      transition: all 0.2s;
      background: transparent;
      cursor: pointer;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }
    .btn-outline:hover { background: var(--teal); color: white; }

    .dashboard-card {
      background: white;
      border-radius: 18px;
      padding: 1.5rem 1.25rem;
      box-shadow: var(--shadow-card);
      border: 1px solid #f0f3f7;
    }
    .dashboard-card .card-title {
      font-size: 13px;
      font-weight: 700;
      color: var(--navy);
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .status-badge {
      display: inline-block;
      padding: 4px 12px;
      border-radius: 50px;
      font-size: 11px;
      font-weight: 600;
    }
    .status-active { background: #e6f7f5; color: #0d7a6e; }
    .status-hidden { background: #f1f5f9; color: #94a3b8; }
    .status-scheduled { background: #fef3c7; color: #92400e; }
.status-expired   { background: #fee2e2; color: #991b1b; }

    /* Upload Zone */
    .upload-zone {
      border: 2px dashed #e2e8f0;
      border-radius: 12px;
      padding: 30px 20px;
      text-align: center;
      cursor: pointer;
      transition: all 0.3s ease;
      background: #fafbfc;
      position: relative;
      min-height: 160px;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
    }
    .upload-zone:hover {
      border-color: var(--teal);
      background: var(--teal-light);
    }
    .upload-zone.dragover {
      border-color: var(--teal);
      background: var(--teal-light);
      transform: scale(1.02);
      box-shadow: 0 0 0 4px rgba(13, 158, 140, 0.1);
    }
    .upload-zone.has-image {
      border-style: solid;
      border-color: var(--teal);
      background: var(--teal-light);
      padding: 15px;
    }
    .upload-zone .upload-icon {
      font-size: 40px;
      color: var(--teal);
      margin-bottom: 10px;
    }
    .upload-zone .upload-text {
      font-size: 14px;
      font-weight: 600;
      color: var(--navy);
      margin-bottom: 4px;
    }
    .upload-zone .upload-subtext {
      font-size: 12px;
      color: #94a3b8;
    }
    .upload-zone .upload-preview {
      max-width: 100%;
      max-height: 200px;
      border-radius: 8px;
      object-fit: cover;
      margin-top: 10px;
    }
    .upload-zone .remove-image {
      position: absolute;
      top: 8px;
      right: 8px;
      background: rgba(220, 38, 38, 0.9);
      color: #fff;
      border: none;
      border-radius: 50%;
      width: 30px;
      height: 30px;
      cursor: pointer;
      display: none;
      align-items: center;
      justify-content: center;
      transition: all 0.2s;
      font-size: 14px;
    }
    .upload-zone .remove-image:hover {
      background: #dc2626;
      transform: scale(1.1);
    }
    .upload-zone.has-image .remove-image {
      display: flex;
    }
    .upload-zone input[type="file"] {
      display: none;
    }
    .upload-zone .file-info {
      font-size: 11px;
      color: var(--teal-dark);
      margin-top: 8px;
      font-weight: 500;
    }
    .upload-zone .upload-progress {
      width: 100%;
      max-width: 300px;
      height: 4px;
      background: #e2e8f0;
      border-radius: 2px;
      margin-top: 10px;
      overflow: hidden;
      display: none;
    }
    .upload-zone .upload-progress .progress-bar {
      height: 100%;
      background: var(--teal);
      border-radius: 2px;
      transition: width 0.3s ease;
      width: 0%;
    }
    .upload-zone.uploading .upload-progress {
      display: block;
    }
    .upload-zone.uploading .upload-icon {
      animation: pulse 1.5s ease-in-out infinite;
    }
    @keyframes pulse {
      0%, 100% { transform: scale(1); }
      50% { transform: scale(1.1); }
    }

    .image-preview-sm {
      width: 40px;
      height: 40px;
      object-fit: cover;
      border-radius: 8px;
      border: 2px solid #e2e8f0;
      transition: all 0.2s;
    }
    .image-preview-sm:hover {
      transform: scale(1.1);
      border-color: var(--teal);
    }

    .current-image-display {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 12px;
      background: #f8fafc;
      border-radius: 8px;
      margin-top: 8px;
      border: 1px solid #e2e8f0;
    }
    .current-image-display img {
      width: 60px;
      height: 60px;
      object-fit: cover;
      border-radius: 6px;
      border: 1px solid #e2e8f0;
    }
    .current-image-display .image-label {
      font-size: 12px;
      color: #6b7280;
    }

    /* Modal */
    .modal-overlay {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: rgba(0,0,0,0.5);
      backdrop-filter: blur(4px);
      z-index: 999;
      justify-content: center;
      align-items: center;
      padding: 20px;
    }
    .modal-overlay.active {
      display: flex;
    }
    .modal {
      background: #fff;
      border-radius: 16px;
      max-width: 600px;
      width: 100%;
      max-height: 90vh;
      overflow-y: auto;
      padding: 30px;
      position: relative;
      animation: modalIn 0.3s ease;
      box-shadow: 0 20px 60px rgba(0,0,0,0.15);
    }
    @keyframes modalIn {
      from { transform: scale(0.9) translateY(20px); opacity: 0; }
      to { transform: scale(1) translateY(0); opacity: 1; }
    }
    .modal-close {
      position: absolute;
      top: 15px;
      right: 20px;
      background: none;
      border: none;
      font-size: 24px;
      cursor: pointer;
      color: #94a3b8;
      padding: 0;
      line-height: 1;
      transition: all 0.2s;
    }
    .modal-close:hover { color: var(--navy); transform: rotate(90deg); }
    .modal h2 {
      font-size: 20px;
      margin: 0 0 6px;
      color: var(--navy);
    }
    .modal .subtitle {
      color: #6b7280;
      font-size: 13px;
      margin: 0 0 20px;
    }
    .modal .form-actions {
      margin-top: 20px;
      display: flex;
      justify-content: flex-end;
      gap: 10px;
      padding-top: 16px;
      border-top: 1px solid #e2e8f0;
    }

    .table-row {
      display: grid;
      grid-template-columns: 0.5fr 0.8fr 1.2fr 0.8fr 0.8fr 0.8fr;
      padding: 10px 0;
      border-bottom: 1px solid #f1f4f8;
      font-size: 13px;
      align-items: center;
    }
    .table-row.head {
      font-weight: 600;
      color: var(--slate);
      font-size: 11px;
      letter-spacing: 0.3px;
      text-transform: uppercase;
      border-bottom: 2px solid #e9edf2;
      padding-bottom: 8px;
    }
    @media (max-width: 640px) {
      .table-row {
        grid-template-columns: 1fr 1fr;
        gap: 4px 8px;
        padding: 12px 0;
        font-size: 12px;
      }
      .table-row.head { display: none; }
    }

    /* Tabs */
    .tab-nav {
      display: flex;
      gap: 4px;
      flex-wrap: wrap;
      border-bottom: 1px solid #e9edf2;
      padding-bottom: 0;
      margin-bottom: 24px;
    }
    .tab-nav a {
      padding: 10px 20px;
      font-size: 13px;
      font-weight: 600;
      color: #64748b;
      text-decoration: none;
      border-bottom: 2.5px solid transparent;
      transition: all 0.2s;
      border-radius: 8px 8px 0 0;
    }
    .tab-nav a:hover { color: var(--teal); background: var(--teal-light); }
    .tab-nav a.active { border-bottom-color: var(--teal); color: var(--teal); background: var(--teal-light); }

    /* main content shift when sidebar open */
    @media (min-width: 1024px) {
      .main-content {
        margin-left: 270px;
      }
    }
    @media (max-width: 1023px) {
      .main-content { margin-left: 0; }
    }
  </style>
</head>
<body>

<!-- ===== MAIN CONTENT ===== -->
<div class="main-content">

  <main class="py-6">
    <div class="container-modern">

      <!-- Header -->
      <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div>
          <h1 class="text-2xl font-bold text-dark-blue">Home Settings</h1>
          <p class="text-sm text-gray-500">Edit everything that appears on the public homepage</p>
        </div>
       
      </div>

      <!-- Tabs -->
      <div class="tab-nav">
        <a href="#settings" class="active">Clinic &amp; Hero</a>
        <a href="#services">Services <span class="text-xs text-gray-400 ml-1">(<?= count($services) ?>)</span></a>
        <a href="#features">Features <span class="text-xs text-gray-400 ml-1">(<?= count($features) ?>)</span></a>
        <a href="#gallery">Gallery <span class="text-xs text-gray-400 ml-1">(<?= count($gallery) ?>)</span></a>
            <a href="#announcements">Announcements <span class="text-xs text-gray-400 ml-1">(<?= count($announcements) ?>)</span></a>
      </div>

      <!-- ===== SETTINGS PANEL ===== -->
      <div id="settings" class="dashboard-card mb-6">
        <div class="card-title mb-4">
          <span><i class="fas fa-hospital mr-2 text-teal"></i> Clinic Info &amp; Hero</span>
          <span class="text-xs text-gray-400">This powers the entire homepage</span>
        </div>

        <form method="post" class="settings-form" enctype="multipart/form-data">
          <input type="hidden" name="action" value="update_settings">

          <!-- Clinic Identity -->
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
            <div class="md:col-span-2">
              <h3 class="text-xs font-bold uppercase tracking-wider text-gray-400 mb-3"><i class="fas fa-address-card mr-2 text-teal"></i> Clinic Identity</h3>
            </div>
            <div class="hidden"><label class="text-xs font-semibold text-gray-600 block mb-1">Clinic Name</label><input type="text" name="clinic_name" value="<?= h($settings['clinic_name']) ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-teal focus:border-transparent"></div>
            <div class="hidden"><label class="text-xs font-semibold text-gray-600 block mb-1">Tagline</label><input type="text" name="clinic_tagline" value="<?= h($settings['clinic_tagline']) ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-teal focus:border-transparent"></div>
            <div><label class="text-xs font-semibold text-gray-600 block mb-1">Phone</label><input type="text" name="phone" value="<?= h($settings['phone']) ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-teal focus:border-transparent"></div>
            <div><label class="text-xs font-semibold text-gray-600 block mb-1">Email</label><input type="email" name="email" value="<?= h($settings['email']) ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-teal focus:border-transparent"></div>
            <div><label class="text-xs font-semibold text-gray-600 block mb-1">Location</label><input type="text" name="location" value="<?= h($settings['location']) ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-teal focus:border-transparent"></div>
            <div><label class="text-xs font-semibold text-gray-600 block mb-1">Clinic Hours</label><input type="text" name="clinic_hours" value="<?= h($settings['clinic_hours']) ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-teal focus:border-transparent"></div>
            <div><label class="text-xs font-semibold text-gray-600 block mb-1">Facebook URL</label><input type="text" name="facebook_url" value="<?= h($settings['facebook_url']) ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-teal focus:border-transparent"></div>
            <div><label class="text-xs font-semibold text-gray-600 block mb-1">Instagram URL</label><input type="text" name="instagram_url" value="<?= h($settings['instagram_url']) ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-teal focus:border-transparent"></div>
            <div><label class="text-xs font-semibold text-gray-600 block mb-1">Messenger URL</label><input type="text" name="messenger_url" value="<?= h($settings['messenger_url']) ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-teal focus:border-transparent"></div>
          </div>

          <!-- Hero Section -->
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
            <div class="md:col-span-2">
              <h3 class="text-xs font-bold uppercase tracking-wider text-gray-400 mb-3"><i class="fas fa-image mr-2 text-teal"></i> Hero Section</h3>
            </div>
            <div><label class="text-xs font-semibold text-gray-600 block mb-1">Badge Text</label><input type="text" name="hero_badge" value="<?= h($settings['hero_badge']) ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-teal focus:border-transparent"></div>
            <div></div>
            <div><label class="text-xs font-semibold text-gray-600 block mb-1">Title Line 1</label><input type="text" name="hero_title_line1" value="<?= h($settings['hero_title_line1']) ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-teal focus:border-transparent"></div>
            <div><label class="text-xs font-semibold text-gray-600 block mb-1">Title Line 2 (highlight)</label><input type="text" name="hero_title_line2" value="<?= h($settings['hero_title_line2']) ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-teal focus:border-transparent"></div>
            <div class="md:col-span-2"><label class="text-xs font-semibold text-gray-600 block mb-1">Subtitle (italic)</label><input type="text" name="hero_subtitle" value="<?= h($settings['hero_subtitle']) ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-teal focus:border-transparent"></div>
            <div class="md:col-span-2"><label class="text-xs font-semibold text-gray-600 block mb-1">Description</label><textarea name="hero_description" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-teal focus:border-transparent resize-y min-h-[60px]"><?= h($settings['hero_description']) ?></textarea></div>
            
            <div class="md:col-span-2">
              <label class="text-xs font-semibold text-gray-600 block mb-1">Hero Background Image</label>
              <div class="upload-zone" id="heroUploadZone">
                <i class="fas fa-cloud-upload-alt upload-icon"></i>
                <div class="upload-text">Drop your hero background image here, or click to browse</div>
                <div class="upload-subtext">JPG, PNG, WEBP • Recommended: 1920x1080 • Max 5MB</div>
                <div class="upload-progress">
                  <div class="progress-bar" id="heroProgressBar"></div>
                </div>
                <input type="file" name="hero_background" id="heroBackgroundInput" accept="image/*">
                <button type="button" class="remove-image" id="heroRemoveImage" title="Remove image">
                  <i class="fas fa-times"></i>
                </button>
              </div>
              <div id="heroCurrentImage" style="display:none;"></div>
              <?php if (!empty($settings['hero_background'])): ?>
              <div class="mt-2">
                <label class="flex items-center gap-2 text-sm text-gray-600">
                  <input type="checkbox" name="remove_hero_bg" value="1" class="rounded border-gray-300 text-teal focus:ring-teal"> Remove current background image
                </label>
              </div>
              <?php endif; ?>
            </div>
          </div>

          <!-- Stats -->
          <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div class="md:col-span-3">
              <h3 class="text-xs font-bold uppercase tracking-wider text-gray-400 mb-3"><i class="fas fa-chart-simple mr-2 text-teal"></i> Hero Stats</h3>
            </div>
            <div><label class="text-xs font-semibold text-gray-600 block mb-1">Stat 1 Value</label><input type="text" name="stat1_value" value="<?= h($settings['stat1_value']) ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-teal focus:border-transparent"></div>
            <div><label class="text-xs font-semibold text-gray-600 block mb-1">Stat 1 Label</label><input type="text" name="stat1_label" value="<?= h($settings['stat1_label']) ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-teal focus:border-transparent"></div>
            <div></div>
            <div><label class="text-xs font-semibold text-gray-600 block mb-1">Stat 2 Value</label><input type="text" name="stat2_value" value="<?= h($settings['stat2_value']) ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-teal focus:border-transparent"></div>
            <div><label class="text-xs font-semibold text-gray-600 block mb-1">Stat 2 Label</label><input type="text" name="stat2_label" value="<?= h($settings['stat2_label']) ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-teal focus:border-transparent"></div>
            <div></div>
            <div><label class="text-xs font-semibold text-gray-600 block mb-1">Stat 3 Value</label><input type="text" name="stat3_value" value="<?= h($settings['stat3_value']) ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-teal focus:border-transparent"></div>
            <div><label class="text-xs font-semibold text-gray-600 block mb-1">Stat 3 Label</label><input type="text" name="stat3_label" value="<?= h($settings['stat3_label']) ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-teal focus:border-transparent"></div>
          </div>

          <!-- About & CTA -->
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="md:col-span-2">
              <h3 class="text-xs font-bold uppercase tracking-wider text-gray-400 mb-3"><i class="fas fa-quote-right mr-2 text-teal"></i> About &amp; CTA</h3>
            </div>
            <div class="md:col-span-2"><label class="text-xs font-semibold text-gray-600 block mb-1">About Heading</label><input type="text" name="about_heading" value="<?= h($settings['about_heading']) ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-teal focus:border-transparent"></div>
            <div class="md:col-span-2"><label class="text-xs font-semibold text-gray-600 block mb-1">About Description</label><textarea name="about_description" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-teal focus:border-transparent resize-y min-h-[60px]"><?= h($settings['about_description']) ?></textarea></div>
            <div class="md:col-span-2"><label class="text-xs font-semibold text-gray-600 block mb-1">CTA Heading</label><input type="text" name="cta_heading" value="<?= h($settings['cta_heading']) ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-teal focus:border-transparent"></div>
            <div class="md:col-span-2"><label class="text-xs font-semibold text-gray-600 block mb-1">CTA Description</label><textarea name="cta_description" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-teal focus:border-transparent resize-y min-h-[60px]"><?= h($settings['cta_description']) ?></textarea></div>
          </div>

          <div class="mt-6 flex justify-end">
            <button type="submit" class="btn-primary"><i class="fas fa-floppy-disk mr-2"></i> Save Changes</button>
          </div>
        </form>
      </div>

      <!-- ===== SERVICES PANEL ===== -->
      <div id="services" class="dashboard-card mb-6">
        <div class="card-title mb-4">
          <span><i class="fas fa-tooth mr-2 text-teal"></i> Dental Services</span>
          <button class="btn-primary text-sm py-1.5 px-4" onclick="openModal('service')"><i class="fas fa-plus mr-1.5"></i> Add Service</button>
        </div>

        <div class="overflow-x-auto">
          <div class="table-row head">
            <span>#</span>
            <span>Image</span>
            <span>Title</span>
            <span>Duration</span>
            <span>Price</span>
            <span class="text-right">Actions</span>
          </div>
          <?php foreach ($services as $s): ?>
          <div class="table-row">
            <span class="text-sm font-medium"><?= (int)$s['sort_order'] ?></span>
            <td>
              <?php if ($s['image']): ?>
                <img src="../../<?= h($s['image']) ?>" alt="<?= h($s['title']) ?>" class="image-preview-sm">
              <?php else: ?>
                <span class="text-xs text-gray-400">No image</span>
              <?php endif; ?>
            </td>
            <span class="font-medium"><?= h($s['title']) ?></span>
            <span class="text-sm text-gray-600"><?= h($s['duration']) ?></span>
            <span class="text-sm font-semibold text-dark-blue">₱<?= number_format($s['price'], 2) ?></span>
            <span class="flex items-center justify-end gap-2">
              <span class="status-badge <?= $s['is_active'] ? 'status-active' : 'status-hidden' ?>"><?= $s['is_active'] ? 'Active' : 'Hidden' ?></span>
              <button class="text-teal hover:bg-teal-light p-1.5 rounded-lg transition" onclick="editService(<?= (int)$s['id'] ?>)" title="Edit"><i class="fas fa-pen text-sm"></i></button>
              <form method="post" class="delete-form inline" data-type="service">
                <input type="hidden" name="action" value="delete_service">
                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                <button type="button" class="text-red-500 hover:bg-red-50 p-1.5 rounded-lg transition delete-btn" title="Delete"><i class="fas fa-trash text-sm"></i></button>
              </form>
            </span>
          </div>
          <?php endforeach; ?>
          <?php if (empty($services)): ?>
          <div class="text-center py-8 text-gray-400 text-sm">No services yet — click "Add Service" to create one.</div>
          <?php endif; ?>
        </div>
      </div>

      <!-- ===== FEATURES PANEL ===== -->
      <div id="features" class="dashboard-card mb-6">
        <div class="card-title mb-4">
          <span><i class="fas fa-star mr-2 text-teal"></i> Why Choose Us</span>
          <button class="btn-primary text-sm py-1.5 px-4" onclick="openModal('feature')"><i class="fas fa-plus mr-1.5"></i> Add Feature</button>
        </div>

        <div class="overflow-x-auto">
          <div class="table-row head">
            <span>#</span>
            <span>Image</span>
            <span>Title</span>
            <span>Description</span>
            <span class="text-right">Actions</span>
          </div>
          <?php foreach ($features as $f): ?>
          <div class="table-row">
            <span class="text-sm font-medium"><?= (int)$f['sort_order'] ?></span>
            <td>
              <?php if ($f['image']): ?>
                <img src="../../<?= h($f['image']) ?>" alt="<?= h($f['title']) ?>" class="image-preview-sm">
              <?php else: ?>
                <span class="text-xs text-gray-400">No image</span>
              <?php endif; ?>
            </td>
            <span class="font-medium"><?= h($f['title']) ?></span>
            <span class="text-sm text-gray-600"><?= h($f['description']) ?></span>
            <span class="flex items-center justify-end gap-2">
              <span class="status-badge <?= $f['is_active'] ? 'status-active' : 'status-hidden' ?>"><?= $f['is_active'] ? 'Active' : 'Hidden' ?></span>
              <button class="text-teal hover:bg-teal-light p-1.5 rounded-lg transition" onclick="editFeature(<?= (int)$f['id'] ?>)" title="Edit"><i class="fas fa-pen text-sm"></i></button>
              <form method="post" class="delete-form inline" data-type="feature">
                <input type="hidden" name="action" value="delete_feature">
                <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                <button type="button" class="text-red-500 hover:bg-red-50 p-1.5 rounded-lg transition delete-btn" title="Delete"><i class="fas fa-trash text-sm"></i></button>
              </form>
            </span>
          </div>
          <?php endforeach; ?>
          <?php if (empty($features)): ?>
          <div class="text-center py-8 text-gray-400 text-sm">No features yet — click "Add Feature" to create one.</div>
          <?php endif; ?>
        </div>
      </div>

      <!-- ===== GALLERY PANEL ===== -->
      <div id="gallery" class="dashboard-card">
        <div class="card-title mb-4">
          <span><i class="fas fa-camera mr-2 text-teal"></i> Clinic Gallery</span>
          <button class="btn-primary text-sm py-1.5 px-4" onclick="openModal('gallery')"><i class="fas fa-plus mr-1.5"></i> Add Gallery Item</button>
        </div>

        <div class="overflow-x-auto">
          <div class="table-row head">
            <span>#</span>
            <span>Image</span>
            <span>Label</span>
            <span class="text-right">Actions</span>
          </div>
          <?php foreach ($gallery as $g): ?>
          <div class="table-row">
            <span class="text-sm font-medium"><?= (int)$g['sort_order'] ?></span>
            <td>
              <?php if ($g['image']): ?>
                <img src="../../<?= h($g['image']) ?>" alt="<?= h($g['tag']) ?>" class="image-preview-sm">
              <?php else: ?>
                <span class="text-xs text-gray-400">No image</span>
              <?php endif; ?>
            </td>
            <span class="font-medium"><?= h($g['tag']) ?></span>
            <span class="flex items-center justify-end gap-2">
              <span class="status-badge <?= $g['is_active'] ? 'status-active' : 'status-hidden' ?>"><?= $g['is_active'] ? 'Active' : 'Hidden' ?></span>
              <button class="text-teal hover:bg-teal-light p-1.5 rounded-lg transition" onclick="editGallery(<?= (int)$g['id'] ?>)" title="Edit"><i class="fas fa-pen text-sm"></i></button>
              <form method="post" class="delete-form inline" data-type="gallery">
                <input type="hidden" name="action" value="delete_gallery">
                <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
                <button type="button" class="text-red-500 hover:bg-red-50 p-1.5 rounded-lg transition delete-btn" title="Delete"><i class="fas fa-trash text-sm"></i></button>
              </form>
            </span>
          </div>
          <?php endforeach; ?>
          <?php if (empty($gallery)): ?>
          <div class="text-center py-8 text-gray-400 text-sm">No gallery items yet — click "Add Gallery Item" to create one.</div>
          <?php endif; ?>
        </div>
      </div>
<!-- ===== ANNOUNCEMENTS PANEL ===== -->
<div id="announcements" class="dashboard-card mb-6">
  <div class="card-title mb-4">
    <span><i class="fas fa-bullhorn mr-2 text-teal"></i> Announcements</span>
    <button class="btn-primary text-sm py-1.5 px-4" onclick="openAnnouncementModal()">
      <i class="fas fa-plus mr-1.5"></i> Add Announcement
    </button>
  </div>

  <div class="overflow-x-auto">
    <div class="table-row head" style="grid-template-columns: 0.4fr 0.8fr 1.4fr 0.7fr 0.7fr 1fr;">
      <span>#</span>
      <span>Image</span>
      <span>Title / Message</span>
      <span>Type</span>
      <span>Layout</span>
      <span class="text-right">Actions</span>
    </div>

    <?php foreach ($announcements as $a): ?>
    <div class="table-row" style="grid-template-columns: 0.4fr 0.8fr 1.4fr 0.7fr 0.7fr 1fr;">
      <span class="text-sm font-medium"><?= (int)$a['sort_order'] ?></span>

      <td>
        <?php if (!empty($a['image'])): ?>
          <img src="../../<?= h($a['image']) ?>" alt="<?= h($a['title']) ?>" class="image-preview-sm">
        <?php else: ?>
          <span class="text-xs text-gray-400">No image</span>
        <?php endif; ?>
      </td>

      <td>
        <div class="font-medium text-dark-blue"><?= h($a['title']) ?></div>
        <div class="text-xs text-gray-500 truncate" style="max-width: 280px;">
          <?= h(mb_strimwidth($a['message'], 0, 70, '…')) ?>
        </div>
        <?php if (!empty($a['cta_text'])): ?>
          <div class="text-[10px] text-teal mt-0.5">
            <i class="fas fa-link"></i> <?= h($a['cta_text']) ?>
            → <span class="text-gray-400"><?= h($a['cta_link']) ?></span>
          </div>
        <?php endif; ?>
      </td>

      <td>
        <span class="status-badge" style="background:
          <?= $a['type']==='promo'   ? '#fef3c7;color:#92400e' :
             ($a['type']==='warning'? '#fed7aa;color:#9a3412' :
             ($a['type']==='success'? '#d1fae5;color:#065f46' :
                                      '#dbeafe;color:#1e40af')) ?>;">
          <i class="fas <?= h($a['icon'] ?: 'fa-bullhorn') ?> mr-1"></i>
          <?= h(ucfirst($a['type'])) ?>
        </span>
      </td>

      <td>
        <span class="status-badge" style="background:#f1f5f9;color:#475569;">
          <?= h(ucfirst($a['layout'])) ?>
        </span>
      </td>

     <td class="flex items-center justify-end gap-2">
    <?php $st = announcementStatus($a); ?>
    <span class="status-badge <?= $st['class'] ?>">
        <?php if ($st['label'] === 'Scheduled'): ?>
            <i class="fas fa-clock mr-1"></i>
        <?php elseif ($st['label'] === 'Expired'): ?>
            <i class="fas fa-hourglass-end mr-1"></i>
        <?php endif; ?>
        <?= $st['label'] ?>
    </span>
        <button class="text-teal hover:bg-teal-light p-1.5 rounded-lg transition"
                onclick="editAnnouncement(<?= (int)$a['id'] ?>)" title="Edit">
          <i class="fas fa-pen text-sm"></i>
        </button>
        <form method="post" class="delete-form inline" data-type="announcement">
          <input type="hidden" name="action" value="delete_announcement">
          <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
          <button type="button" class="text-red-500 hover:bg-red-50 p-1.5 rounded-lg transition delete-btn" title="Delete">
            <i class="fas fa-trash text-sm"></i>
          </button>
        </form>
      </td>
    </div>
    <?php endforeach; ?>

    <?php if (empty($announcements)): ?>
      <div class="text-center py-8 text-gray-400 text-sm">
        No announcements yet — click "Add Announcement" to create one.
      </div>
    <?php endif; ?>
  </div>
</div>
    </div>
  </main>

     <div class="mt-8 text-center text-[11px] text-gray-400 border-t border-gray-200 pt-5 pb-5">
        <i class="fas fa-tooth mr-1 text-teal/50"></i> Wansi Calacat Dental Clinic · Landing Page Settings
        
      </div>
</div>

<!-- ===== MODALS ===== -->

<!-- Service Modal -->
<div class="modal-overlay" id="serviceModal">
  <div class="modal">
    <button class="modal-close" onclick="closeModal('serviceModal')">&times;</button>
    <h2 id="serviceModalTitle">Add New Service</h2>
    <p class="subtitle">Fill in the details for this dental service.</p>
    <form method="post" id="serviceForm" class="modal-form" enctype="multipart/form-data">
      <input type="hidden" name="action" value="save_service">
      <input type="hidden" name="id" id="serviceId" value="">
      <div class="grid grid-cols-1 gap-4">
        <div><label class="text-xs font-semibold text-gray-600 block mb-1">Title *</label><input type="text" name="title" id="serviceTitle" required class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-teal focus:border-transparent"></div>
        <div><label class="text-xs font-semibold text-gray-600 block mb-1">Description</label><input type="text" name="description" id="serviceDescription" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-teal focus:border-transparent"></div>
        <div>
          <label class="text-xs font-semibold text-gray-600 block mb-1">Service Image</label>
          <div class="upload-zone" id="serviceUploadZone">
            <i class="fas fa-cloud-upload-alt upload-icon"></i>
            <div class="upload-text">Drop your image here, or click to browse</div>
            <div class="upload-subtext">JPG, PNG, GIF, WEBP, SVG • Max 5MB</div>
            <div class="upload-progress"><div class="progress-bar" id="serviceProgressBar"></div></div>
            <input type="file" name="service_image" id="serviceImage" accept="image/*">
            <button type="button" class="remove-image" id="serviceRemoveImage" title="Remove image"><i class="fas fa-times"></i></button>
          </div>
          <div id="serviceCurrentImage" style="display:none;"></div>
        </div>
        <div class="grid grid-cols-2 gap-4">
          <div><label class="text-xs font-semibold text-gray-600 block mb-1">Duration</label><input type="text" name="duration" id="serviceDuration" value="30 min" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-teal focus:border-transparent"></div>
          <div><label class="text-xs font-semibold text-gray-600 block mb-1">Price (₱)</label><input type="number" step="0.01" name="price" id="servicePrice" value="0" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-teal focus:border-transparent"></div>
        </div>
        <div class="grid grid-cols-2 gap-4">
          <div><label class="text-xs font-semibold text-gray-600 block mb-1">Sort Order</label><input type="number" name="sort_order" id="serviceOrder" value="<?= count($services) + 1 ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-teal focus:border-transparent"></div>
          <div class="flex items-center"><label class="flex items-center gap-2 text-sm font-medium text-gray-700"><input type="checkbox" name="is_active" id="serviceActive" checked class="rounded border-gray-300 text-teal focus:ring-teal"> Show on homepage</label></div>
        </div>
      </div>
      <div class="form-actions">
        <button type="button" class="btn-outline text-sm" onclick="closeModal('serviceModal')">Cancel</button>
        <button type="submit" class="btn-primary text-sm"><i class="fas fa-floppy-disk mr-2"></i> Save Service</button>
      </div>
    </form>
  </div>
</div>

<!-- Feature Modal -->
<div class="modal-overlay" id="featureModal">
  <div class="modal">
    <button class="modal-close" onclick="closeModal('featureModal')">&times;</button>
    <h2 id="featureModalTitle">Add New Feature</h2>
    <p class="subtitle">Fill in the details for this feature.</p>
    <form method="post" id="featureForm" class="modal-form" enctype="multipart/form-data">
      <input type="hidden" name="action" value="save_feature">
      <input type="hidden" name="id" id="featureId" value="">
      <div class="grid grid-cols-1 gap-4">
        <div><label class="text-xs font-semibold text-gray-600 block mb-1">Title *</label><input type="text" name="title" id="featureTitle" required class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-teal focus:border-transparent"></div>
        <div><label class="text-xs font-semibold text-gray-600 block mb-1">Description</label><input type="text" name="description" id="featureDescription" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-teal focus:border-transparent"></div>
        <div>
          <label class="text-xs font-semibold text-gray-600 block mb-1">Feature Image</label>
          <div class="upload-zone" id="featureUploadZone">
            <i class="fas fa-cloud-upload-alt upload-icon"></i>
            <div class="upload-text">Drop your image here, or click to browse</div>
            <div class="upload-subtext">JPG, PNG, GIF, WEBP, SVG • Max 5MB</div>
            <div class="upload-progress"><div class="progress-bar" id="featureProgressBar"></div></div>
            <input type="file" name="feature_image" id="featureImage" accept="image/*">
            <button type="button" class="remove-image" id="featureRemoveImage" title="Remove image"><i class="fas fa-times"></i></button>
          </div>
          <div id="featureCurrentImage" style="display:none;"></div>
        </div>
        <div class="grid grid-cols-2 gap-4">
          <div><label class="text-xs font-semibold text-gray-600 block mb-1">Sort Order</label><input type="number" name="sort_order" id="featureOrder" value="<?= count($features) + 1 ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-teal focus:border-transparent"></div>
          <div class="flex items-center"><label class="flex items-center gap-2 text-sm font-medium text-gray-700"><input type="checkbox" name="is_active" id="featureActive" checked class="rounded border-gray-300 text-teal focus:ring-teal"> Show on homepage</label></div>
        </div>
      </div>
      <div class="form-actions">
        <button type="button" class="btn-outline text-sm" onclick="closeModal('featureModal')">Cancel</button>
        <button type="submit" class="btn-primary text-sm"><i class="fas fa-floppy-disk mr-2"></i> Save Feature</button>
      </div>
    </form>
  </div>
</div>

<!-- Gallery Modal -->
<div class="modal-overlay" id="galleryModal">
  <div class="modal">
    <button class="modal-close" onclick="closeModal('galleryModal')">&times;</button>
    <h2 id="galleryModalTitle">Add New Gallery Item</h2>
    <p class="subtitle">Fill in the details for this gallery item.</p>
    <form method="post" id="galleryForm" class="modal-form" enctype="multipart/form-data">
      <input type="hidden" name="action" value="save_gallery">
      <input type="hidden" name="id" id="galleryId" value="">
      <div class="grid grid-cols-1 gap-4">
        <div><label class="text-xs font-semibold text-gray-600 block mb-1">Label *</label><input type="text" name="tag" id="galleryTag" required class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-teal focus:border-transparent"></div>
        <div><label class="text-xs font-semibold text-gray-600 block mb-1">Sort Order</label><input type="number" name="sort_order" id="galleryOrder" value="<?= count($gallery) + 1 ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-teal focus:border-transparent"></div>
        <div>
          <label class="text-xs font-semibold text-gray-600 block mb-1">Gallery Image</label>
          <div class="upload-zone" id="galleryUploadZone">
            <i class="fas fa-cloud-upload-alt upload-icon"></i>
            <div class="upload-text">Drop your image here, or click to browse</div>
            <div class="upload-subtext">JPG, PNG, GIF, WEBP, SVG • Max 5MB</div>
            <div class="upload-progress"><div class="progress-bar" id="galleryProgressBar"></div></div>
            <input type="file" name="gallery_image" id="galleryImage" accept="image/*">
            <button type="button" class="remove-image" id="galleryRemoveImage" title="Remove image"><i class="fas fa-times"></i></button>
          </div>
          <div id="galleryCurrentImage" style="display:none;"></div>
        </div>
        <div class="flex items-center"><label class="flex items-center gap-2 text-sm font-medium text-gray-700"><input type="checkbox" name="is_active" id="galleryActive" checked class="rounded border-gray-300 text-teal focus:ring-teal"> Show on homepage</label></div>
      </div>
      <div class="form-actions">
        <button type="button" class="btn-outline text-sm" onclick="closeModal('galleryModal')">Cancel</button>
        <button type="submit" class="btn-primary text-sm"><i class="fas fa-floppy-disk mr-2"></i> Save Gallery Item</button>
      </div>
    </form>
  </div>
</div>
<!-- Announcement Modal -->
<div class="modal-overlay" id="announcementModal">
  <div class="modal" style="max-width: 680px;">
    <button class="modal-close" onclick="closeModal('announcementModal')">&times;</button>
    <h2 id="announcementModalTitle">Add New Announcement</h2>
    <p class="subtitle">Fill in the details for this announcement.</p>

    <form method="post" id="announcementForm" class="modal-form" enctype="multipart/form-data">
      <input type="hidden" name="action" value="save_announcement">
      <input type="hidden" name="id" id="announcementId" value="">

      <div class="grid grid-cols-1 gap-4">

        <!-- Title + Type -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div class="md:col-span-2">
            <label class="text-xs font-semibold text-gray-600 block mb-1">Title *</label>
            <input type="text" name="title" id="announcementTitle" required
                   class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-teal focus:border-transparent">
          </div>
          <div>
            <label class="text-xs font-semibold text-gray-600 block mb-1">Type</label>
            <select name="type" id="announcementType"
                    class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-teal focus:border-transparent">
              <option value="info">Info</option>
              <option value="promo">Promo</option>
              <option value="warning">Warning</option>
              <option value="success">Success</option>
            </select>
          </div>
        </div>

        <!-- Message -->
        <div>
          <label class="text-xs font-semibold text-gray-600 block mb-1">Message *</label>
          <textarea name="message" id="announcementMessage" required rows="3"
                    class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-teal focus:border-transparent resize-y"></textarea>
        </div>

        <!-- Image -->
        <div>
          <label class="text-xs font-semibold text-gray-600 block mb-1">Announcement Image</label>
          <div class="upload-zone" id="announcementUploadZone">
            <i class="fas fa-cloud-upload-alt upload-icon"></i>
            <div class="upload-text">Drop your image here, or click to browse</div>
            <div class="upload-subtext">JPG, PNG, GIF, WEBP, SVG • Max 5MB</div>
            <div class="upload-progress"><div class="progress-bar" id="announcementProgressBar"></div></div>
            <input type="file" name="announcement_image" id="announcementImage" accept="image/*">
            <button type="button" class="remove-image" id="announcementRemoveImage" title="Remove image">
              <i class="fas fa-times"></i>
            </button>
          </div>
          <div id="announcementCurrentImage" style="display:none;"></div>
        </div>

        <!-- CTA + Layout + Icon -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div>
            <label class="text-xs font-semibold text-gray-600 block mb-1">CTA Text</label>
            <input type="text" name="cta_text" id="announcementCtaText" placeholder="Book Now"
                   class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-teal focus:border-transparent">
          </div>
         <div>
  <label class="text-xs font-semibold text-gray-600 block mb-1">CTA Link</label>
  <select name="cta_link" id="announcementCtaLink"
          onchange="handleCtaLinkChange(this)"
          class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-teal focus:border-transparent">
    <option value="">— No link —</option>

    

    <optgroup label="Jump to Section (same page)">
      <option value="#home">Home / Hero</option>
      <option value="#services">Services</option>
      <option value="#gallery">Gallery</option>
      <option value="#about">About / Why Choose Us</option>
      <option value="#contact">Contact</option>
    </optgroup>

    <optgroup label="Other Pages">
      <option value="track-appointment.php">Track Appointment</option>
       <option value="index.php">Homepage</option>
    </optgroup>

    <optgroup label="External / Contact">
      <option value="tel:">Phone Call (edit after picking)</option>
      <option value="mailto:">Send Email (edit after picking)</option>
      <option value="https://m.me/">Messenger Chat</option>
      <option value="https://facebook.com/">Facebook Page</option>
      <option value="https://instagram.com/">Instagram</option>
    </optgroup>

    <option value="__custom__">✎ Custom URL…</option>
  </select>

  <!-- Custom URL input (hidden until "Custom URL" is picked) -->
  <input type="text" name="cta_link_custom" id="announcementCtaLinkCustom"
         placeholder="https://example.com or #section"
         style="display:none; margin-top:8px;"
         class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-teal focus:border-transparent">

  <p class="text-[10px] text-gray-400 mt-1">
    Pick a built-in option, or choose <b>Custom URL</b> to type your own.
  </p>
</div>
          <div>
            <label class="text-xs font-semibold text-gray-600 block mb-1">Layout</label>
            <select name="layout" id="announcementLayout"
                    class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-teal focus:border-transparent">
              <option value="banner">Top Banner Bar</option>
              <option value="floating">Floating Card</option>
              <option value="inline">Inline Card</option>
            </select>
          </div>
        </div>

        <!-- Icon + Dates -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div>
            <label class="text-xs font-semibold text-gray-600 block mb-1">Icon class</label>
            <input type="text" name="icon" id="announcementIcon" value="fa-bullhorn"
                   class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-teal focus:border-transparent">
            <p class="text-[10px] text-gray-400 mt-1">e.g. fa-tag, fa-tooth, fa-calendar</p>
          </div>
         <div>
    <label class="text-xs font-semibold text-gray-600 block mb-1">Start date (optional)</label>
    <input type="date" name="start_date" id="announcementStart"
           class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-teal focus:border-transparent">
    <p class="text-[10px] text-gray-400 mt-1">
        Leave blank to show <b>immediately</b>. Set a future date to schedule it.
    </p>
</div>
<div>
    <label class="text-xs font-semibold text-gray-600 block mb-1">End date (optional)</label>
    <input type="date" name="end_date" id="announcementEnd"
           class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-teal focus:border-transparent">
    <p class="text-[10px] text-gray-400 mt-1">
        Leave blank to <b>run indefinitely</b>.
    </p>
</div>
        </div>

        <!-- Sort + checkboxes -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
          <div>
            <label class="text-xs font-semibold text-gray-600 block mb-1">Sort Order</label>
            <input type="number" name="sort_order" id="announcementOrder"
                   value="<?= count($announcements) + 1 ?>"
                   class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-teal focus:border-transparent">
          </div>
          <label class="flex items-center gap-2 text-sm font-medium text-gray-700">
            <input type="checkbox" name="is_active" id="announcementActive" checked
                   class="rounded border-gray-300 text-teal focus:ring-teal">
            Show on homepage
          </label>
          <label class="flex items-center gap-2 text-sm font-medium text-gray-700">
            <input type="checkbox" name="is_dismissible" id="announcementDismiss" checked
                   class="rounded border-gray-300 text-teal focus:ring-teal">
            Allow users to dismiss
          </label>
        </div>

      </div>

      <div class="form-actions">
        <button type="button" class="btn-outline text-sm" onclick="closeModal('announcementModal')">Cancel</button>
        <button type="submit" class="btn-primary text-sm">
          <i class="fas fa-floppy-disk mr-2"></i> Save Announcement
        </button>
      </div>
    </form>
  </div>
</div>
<script>
// Drag & Drop Upload Handler
class DragDropUpload {
    constructor(config) {
        this.zone = document.getElementById(config.zoneId);
        this.input = document.getElementById(config.inputId);
        this.progressBar = document.getElementById(config.progressId);
        this.removeBtn = document.getElementById(config.removeId);
        this.currentImageContainer = document.getElementById(config.currentImageId);
        this.previewImage = null;
        this.hasImage = false;
        this.uploadedFile = null;
        this.init();
    }

    init() {
        this.zone.addEventListener('click', (e) => {
            if (e.target === this.removeBtn || this.removeBtn.contains(e.target)) return;
            this.input.click();
        });

        this.zone.addEventListener('dragover', (e) => {
            e.preventDefault();
            this.zone.classList.add('dragover');
        });

        this.zone.addEventListener('dragleave', (e) => {
            e.preventDefault();
            this.zone.classList.remove('dragover');
        });

        this.zone.addEventListener('drop', (e) => {
            e.preventDefault();
            this.zone.classList.remove('dragover');
            if (e.dataTransfer.files.length) {
                this.handleFile(e.dataTransfer.files[0]);
            }
        });

        this.input.addEventListener('change', (e) => {
            if (this.input.files.length) {
                this.handleFile(this.input.files[0]);
            }
        });

        if (this.removeBtn) {
            this.removeBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                this.removeImage();
            });
        }
    }

    handleFile(file) {
        const validTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
        if (!validTypes.includes(file.type)) {
            Swal.fire({
                icon: 'error',
                title: 'Invalid File Type',
                text: 'Please upload a JPG, PNG, GIF, WEBP, or SVG image.',
                confirmButtonColor: '#dc2626'
            });
            return;
        }

        if (file.size > 5 * 1024 * 1024) {
            Swal.fire({
                icon: 'error',
                title: 'File Too Large',
                text: 'File size must be less than 5MB.',
                confirmButtonColor: '#dc2626'
            });
            return;
        }

        this.zone.classList.add('uploading');
        let progress = 0;
        const interval = setInterval(() => {
            progress += Math.random() * 15;
            if (progress >= 100) {
                progress = 100;
                clearInterval(interval);
                setTimeout(() => {
                    this.zone.classList.remove('uploading');
                }, 300);
            }
            this.progressBar.style.width = Math.min(progress, 100) + '%';
        }, 100);

        const reader = new FileReader();
        reader.onload = (e) => {
            const existingPreview = this.zone.querySelector('.upload-preview');
            if (existingPreview) existingPreview.remove();
            
            const existingInfo = this.zone.querySelector('.file-info');
            if (existingInfo) existingInfo.remove();

            const img = document.createElement('img');
            img.src = e.target.result;
            img.className = 'upload-preview';
            img.alt = 'Upload preview';
            this.zone.appendChild(img);
            
            const info = document.createElement('div');
            info.className = 'file-info';
            info.innerHTML = `<i class="fas fa-check-circle"></i> ${file.name} (${(file.size / 1024 / 1024).toFixed(2)} MB)`;
            this.zone.appendChild(info);
            
            this.zone.classList.add('has-image');
            this.hasImage = true;
            this.uploadedFile = file;
            this.previewImage = e.target.result;
        };
        reader.readAsDataURL(file);
    }

    removeImage() {
        this.zone.classList.remove('has-image', 'uploading');
        this.progressBar.style.width = '0%';
        
        const preview = this.zone.querySelector('.upload-preview');
        if (preview) preview.remove();
        const info = this.zone.querySelector('.file-info');
        if (info) info.remove();
        
        this.input.value = '';
        this.hasImage = false;
        this.uploadedFile = null;
        this.previewImage = null;
        
        if (this.currentImageContainer) {
            this.currentImageContainer.style.display = 'block';
        }
    }

    setCurrentImage(imagePath) {
        if (imagePath) {
            this.currentImageContainer.innerHTML = `
                <div class="current-image-display">
                    <img src="../../${imagePath}" alt="Current image">
                    <span class="image-label">Current image</span>
                </div>
            `;
            this.currentImageContainer.style.display = 'block';
        } else {
            this.currentImageContainer.style.display = 'none';
        }
    }
}

// Tab switching
document.querySelectorAll('.tab-nav a').forEach(function(tab) {
    tab.addEventListener('click', function(e) {
        e.preventDefault();
        const target = this.getAttribute('href').substring(1);
        
        // Update active tab
        document.querySelectorAll('.tab-nav a').forEach(t => t.classList.remove('active'));
        this.classList.add('active');
        
        // Show corresponding panel
        document.querySelectorAll('.dashboard-card').forEach(card => {
            card.style.display = 'none';
        });
        const targetPanel = document.getElementById(target);
        if (targetPanel) {
            targetPanel.style.display = 'block';
        }
    });
});

// Show first panel by default
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.dashboard-card').forEach((card, index) => {
        if (index === 0) card.style.display = 'block';
        else card.style.display = 'none';
    });

    // Initialize upload zones
    const heroUpload = new DragDropUpload({
        zoneId: 'heroUploadZone',
        inputId: 'heroBackgroundInput',
        progressId: 'heroProgressBar',
        removeId: 'heroRemoveImage',
        currentImageId: 'heroCurrentImage'
    });
    <?php if (!empty($settings['hero_background'])): ?>
    heroUpload.setCurrentImage('<?= $settings['hero_background'] ?>');
    <?php endif; ?>

    new DragDropUpload({
        zoneId: 'serviceUploadZone',
        inputId: 'serviceImage',
        progressId: 'serviceProgressBar',
        removeId: 'serviceRemoveImage',
        currentImageId: 'serviceCurrentImage'
    });

    new DragDropUpload({
        zoneId: 'featureUploadZone',
        inputId: 'featureImage',
        progressId: 'featureProgressBar',
        removeId: 'featureRemoveImage',
        currentImageId: 'featureCurrentImage'
    });

    new DragDropUpload({
        zoneId: 'galleryUploadZone',
        inputId: 'galleryImage',
        progressId: 'galleryProgressBar',
        removeId: 'galleryRemoveImage',
        currentImageId: 'galleryCurrentImage'
    });

    <?php if (!empty($success)): ?>
    Swal.fire({
        icon: 'success',
        title: 'Success!',
        text: <?= json_encode($success) ?>,
        timer: 2000,
        showConfirmButton: false
    });
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
    Swal.fire({
        icon: 'error',
        title: 'Error!',
        text: <?= json_encode(implode(', ', $errors)) ?>,
        confirmButtonColor: '#dc2626'
    });
    <?php endif; ?>

    // Delete buttons
    document.querySelectorAll('.delete-btn').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const form = this.closest('.delete-form');
            const type = form.dataset.type || 'item';
            
            Swal.fire({
                title: 'Are you sure?',
                text: `This ${type} will be permanently deleted!`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Yes, delete it!',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
        });
    });


    // Announcement upload zone
new DragDropUpload({
    zoneId: 'announcementUploadZone',
    inputId: 'announcementImage',
    progressId: 'announcementProgressBar',
    removeId: 'announcementRemoveImage',
    currentImageId: 'announcementCurrentImage'
});
});

// Modal functions
function openModal(type) {
    const modalMap = {
        'service': 'serviceModal',
        'feature': 'featureModal',
        'gallery': 'galleryModal'
    };
    const modalId = modalMap[type];
    if (modalId) {
        document.getElementById(modalId).classList.add('active');
        document.body.style.overflow = 'hidden';
    }
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('active');
    document.body.style.overflow = '';
}

// Edit functions
function editService(id) {
    <?php foreach ($services as $s): ?>
    if (id === <?= (int)$s['id'] ?>) {
        document.getElementById('serviceId').value = '<?= (int)$s['id'] ?>';
        document.getElementById('serviceTitle').value = '<?= addslashes($s['title']) ?>';
        document.getElementById('serviceDescription').value = '<?= addslashes($s['description']) ?>';
        document.getElementById('serviceDuration').value = '<?= addslashes($s['duration']) ?>';
        document.getElementById('servicePrice').value = '<?= $s['price'] ?>';
        document.getElementById('serviceOrder').value = '<?= (int)$s['sort_order'] ?>';
        document.getElementById('serviceActive').checked = <?= $s['is_active'] ? 'true' : 'false' ?>;
        document.getElementById('serviceModalTitle').textContent = 'Edit Service';
        
        const uploadZone = document.getElementById('serviceUploadZone');
        const currentImageContainer = document.getElementById('serviceCurrentImage');
        
        const preview = uploadZone.querySelector('.upload-preview');
        if (preview) preview.remove();
        const info = uploadZone.querySelector('.file-info');
        if (info) info.remove();
        uploadZone.classList.remove('has-image');
        document.getElementById('serviceImage').value = '';
        
        <?php if ($s['image']): ?>
        currentImageContainer.innerHTML = `
            <div class="current-image-display">
                <img src="../../<?= $s['image'] ?>" alt="Current service image">
                <span class="image-label">Current image</span>
            </div>
        `;
        currentImageContainer.style.display = 'block';
        <?php else: ?>
        currentImageContainer.style.display = 'none';
        <?php endif; ?>
        
        document.getElementById('serviceModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    <?php endforeach; ?>
}

function editFeature(id) {
    <?php foreach ($features as $f): ?>
    if (id === <?= (int)$f['id'] ?>) {
        document.getElementById('featureId').value = '<?= (int)$f['id'] ?>';
        document.getElementById('featureTitle').value = '<?= addslashes($f['title']) ?>';
        document.getElementById('featureDescription').value = '<?= addslashes($f['description']) ?>';
        document.getElementById('featureOrder').value = '<?= (int)$f['sort_order'] ?>';
        document.getElementById('featureActive').checked = <?= $f['is_active'] ? 'true' : 'false' ?>;
        document.getElementById('featureModalTitle').textContent = 'Edit Feature';
        
        const uploadZone = document.getElementById('featureUploadZone');
        const currentImageContainer = document.getElementById('featureCurrentImage');
        
        const preview = uploadZone.querySelector('.upload-preview');
        if (preview) preview.remove();
        const info = uploadZone.querySelector('.file-info');
        if (info) info.remove();
        uploadZone.classList.remove('has-image');
        document.getElementById('featureImage').value = '';
        
        <?php if ($f['image']): ?>
        currentImageContainer.innerHTML = `
            <div class="current-image-display">
                <img src="../../<?= $f['image'] ?>" alt="Current feature image">
                <span class="image-label">Current image</span>
            </div>
        `;
        currentImageContainer.style.display = 'block';
        <?php else: ?>
        currentImageContainer.style.display = 'none';
        <?php endif; ?>
        
        document.getElementById('featureModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    <?php endforeach; ?>
}

function editGallery(id) {
    <?php foreach ($gallery as $g): ?>
    if (id === <?= (int)$g['id'] ?>) {
        document.getElementById('galleryId').value = '<?= (int)$g['id'] ?>';
        document.getElementById('galleryTag').value = '<?= addslashes($g['tag']) ?>';
        document.getElementById('galleryOrder').value = '<?= (int)$g['sort_order'] ?>';
        document.getElementById('galleryActive').checked = <?= $g['is_active'] ? 'true' : 'false' ?>;
        document.getElementById('galleryModalTitle').textContent = 'Edit Gallery Item';
        
        const uploadZone = document.getElementById('galleryUploadZone');
        const currentImageContainer = document.getElementById('galleryCurrentImage');
        
        const preview = uploadZone.querySelector('.upload-preview');
        if (preview) preview.remove();
        const info = uploadZone.querySelector('.file-info');
        if (info) info.remove();
        uploadZone.classList.remove('has-image');
        document.getElementById('galleryImage').value = '';
        
        <?php if ($g['image']): ?>
        currentImageContainer.innerHTML = `
            <div class="current-image-display">
                <img src="../../<?= $g['image'] ?>" alt="Current gallery image">
                <span class="image-label">Current image</span>
            </div>
        `;
        currentImageContainer.style.display = 'block';
        <?php else: ?>
        currentImageContainer.style.display = 'none';
        <?php endif; ?>
        
        document.getElementById('galleryModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    <?php endforeach; ?>
}

// Close modals with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.active').forEach(function(modal) {
            modal.classList.remove('active');
            document.body.style.overflow = '';
        });
    }
});

// Close modal when clicking overlay
document.querySelectorAll('.modal-overlay').forEach(function(overlay) {
    overlay.addEventListener('click', function(e) {
        if (e.target === this) {
            this.classList.remove('active');
            document.body.style.overflow = '';
        }
    });
});










// ====================================================================
// ANNOUNCEMENTS: open + edit
// ====================================================================
function openAnnouncementModal() {
    // Reset form for "Add"
    document.getElementById('announcementId').value = '';
    document.getElementById('announcementTitle').value = '';
    document.getElementById('announcementMessage').value = '';
    document.getElementById('announcementCtaText').value = '';
  const ctaSelect = document.getElementById('announcementCtaLink');
const ctaCustom = document.getElementById('announcementCtaLinkCustom');
ctaSelect.disabled = false;      // ← add this, in case a prior state left it disabled
ctaSelect.value = '';
ctaCustom.value = '';
ctaCustom.style.display = 'none';
    // ... rest
    document.getElementById('announcementType').value = 'info';
    document.getElementById('announcementLayout').value = 'banner';
    document.getElementById('announcementIcon').value = 'fa-bullhorn';
    document.getElementById('announcementStart').value = '';
    document.getElementById('announcementEnd').value = '';
    document.getElementById('announcementOrder').value = <?= count($announcements) + 1 ?>;
    document.getElementById('announcementActive').checked = true;
    document.getElementById('announcementDismiss').checked = true;
    document.getElementById('announcementModalTitle').textContent = 'Add New Announcement';

    // Reset upload zone
    const zone = document.getElementById('announcementUploadZone');
    const preview = zone.querySelector('.upload-preview'); if (preview) preview.remove();
    const info    = zone.querySelector('.file-info');      if (info)    info.remove();
    zone.classList.remove('has-image');
    document.getElementById('announcementImage').value = '';

    document.getElementById('announcementCurrentImage').style.display = 'none';
    document.getElementById('announcementCurrentImage').innerHTML = '';

    document.getElementById('announcementModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function editAnnouncement(id) {
    <?php foreach ($announcements as $a): ?>
    if (id === <?= (int)$a['id'] ?>) {
        document.getElementById('announcementId').value       = '<?= (int)$a['id'] ?>';
        document.getElementById('announcementTitle').value    = <?= json_encode($a['title']) ?>;
        document.getElementById('announcementMessage').value  = <?= json_encode($a['message']) ?>;
        document.getElementById('announcementCtaText').value  = <?= json_encode($a['cta_text'] ?? '') ?>;
setCtaLinkValue(<?= json_encode($a['cta_link'] ?? '') ?>);
        document.getElementById('announcementType').value     = '<?= h($a['type']) ?>';
        document.getElementById('announcementLayout').value   = '<?= h($a['layout']) ?>';
        document.getElementById('announcementIcon').value     = '<?= h($a['icon'] ?? 'fa-bullhorn') ?>';
        document.getElementById('announcementStart').value    = '<?= h($a['start_date'] ?? '') ?>';
        document.getElementById('announcementEnd').value      = '<?= h($a['end_date'] ?? '') ?>';
        document.getElementById('announcementOrder').value    = '<?= (int)$a['sort_order'] ?>';
        document.getElementById('announcementActive').checked = <?= $a['is_active'] ? 'true' : 'false' ?>;
        document.getElementById('announcementDismiss').checked= <?= $a['is_dismissible'] ? 'true' : 'false' ?>;
        document.getElementById('announcementModalTitle').textContent = 'Edit Announcement';

        // Reset upload zone first
        const zone = document.getElementById('announcementUploadZone');
        const preview = zone.querySelector('.upload-preview'); if (preview) preview.remove();
        const info    = zone.querySelector('.file-info');      if (info)    info.remove();
        zone.classList.remove('has-image');
        document.getElementById('announcementImage').value = '';

        // Show current image if exists
        const currentBox = document.getElementById('announcementCurrentImage');
        <?php if (!empty($a['image'])): ?>
        currentBox.innerHTML = `
            <div class="current-image-display">
                <img src="../../<?= h($a['image']) ?>" alt="Current announcement image">
                <span class="image-label">Current image</span>
            </div>`;
        currentBox.style.display = 'block';
        <?php else: ?>
        currentBox.innerHTML = '';
        currentBox.style.display = 'none';
        <?php endif; ?>

        document.getElementById('announcementModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    <?php endforeach; ?>
}










// ====================================================================
// CTA LINK DROPDOWN HANDLING
// ====================================================================

// Called when the select changes
function handleCtaLinkChange(select) {
    const custom = document.getElementById('announcementCtaLinkCustom');
    if (!custom) return;

    if (select.value === '__custom__') {
        custom.style.display = 'block';
        custom.focus();
    } else {
        custom.style.display = 'none';
        custom.value = '';
    }
}

// Set the CTA link select + custom input based on a saved value
function setCtaLinkValue(savedValue) {
    const select = document.getElementById('announcementCtaLink');
    const custom = document.getElementById('announcementCtaLinkCustom');
    if (!select || !custom) return;

    savedValue = savedValue || '';

    // Is the saved value one of the built-in options?
    const optionExists = Array.from(select.options).some(opt => opt.value === savedValue);

    if (optionExists && savedValue !== '') {
        select.value = savedValue;
        custom.value = '';
        custom.style.display = 'none';
    } else if (savedValue === '') {
        select.value = '';
        custom.value = '';
        custom.style.display = 'none';
    } else {
        // Custom value — select the "Custom URL" option and fill the input
        select.value = '__custom__';
        custom.value = savedValue;
        custom.style.display = 'block';
    }
}

document.getElementById('announcementForm')?.addEventListener('submit', function(e) {
    const select = document.getElementById('announcementCtaLink');
    const custom = document.getElementById('announcementCtaLinkCustom');
    if (!select || !custom) return;

    // If Custom URL was chosen, validate
    if (select.value === '__custom__') {
        if (!custom.value.trim()) {
            e.preventDefault();
            Swal.fire({
                icon: 'warning',
                title: 'Custom URL Required',
                text: 'You selected "Custom URL" but didn\'t enter one.',
                confirmButtonColor: '#0d9e8c'
            });
            custom.focus();
            return false;
        }
    }
    // No DOM mutation — the PHP side already handles __custom__ by reading
    // $_POST['cta_link_custom']. So we do nothing here.
});
</script>

</body>
</html>