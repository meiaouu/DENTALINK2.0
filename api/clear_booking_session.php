<?php
session_start();
unset($_SESSION['booking_data']);
echo json_encode(['success' => true]);
?>