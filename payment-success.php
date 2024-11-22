<?php
require_once __DIR__ . '/../../../App/Core/Database.php';  
use App\Core\Database;

if ($_GET['status'] == 'success' && isset($_GET['booking_id'])) {
    $booking_id = $_GET['booking_id'];

    try {
        $db = new Database();
        $connection = $db->connect();

        $query = "UPDATE tbl_booking SET payment_status = 'paid' WHERE booking_id = ?";
        $stmt = $connection->prepare($query);
        $stmt->bind_param('i', $booking_id); // 'i' for integer
        $stmt->execute();

        if ($stmt->affected_rows > 0) {
            echo "Payment status updated to 'paid'.";
        } else {
            echo "Booking not found or already updated.";
        }

        $subject = "Payment Successful";
        $message = "Dear Customer, your payment was successful.";

        $mail = require __DIR__ . '/../../../resource/view/auth/mailer.php';
        
        try {
            $customerEmail = 'samplemuna@example.com';
            $customerName = 'Sample muna';

            $mail->setFrom('ec-clean@gmail.com', 'Ec-Clean Water Systems');
            $mail->addAddress($customerEmail, $customerName);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $message;

            $mail->send();
            echo 'Payment successful email sent';
        } catch (Exception $e) {
            echo 'Error: ' . $mail->ErrorInfo;
        }

    } catch (Exception $e) {
        echo "Error updating payment status: " . $e->getMessage();
    }
} else {
    echo "Payment failed or status not recognized.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Success</title>
    <script>
        window.onload = function() {
            setTimeout(function() {
                window.close();
            }, 3000);
        };
    </script>
</head>
<body>
    <h1>Payment Successful</h1>
    <p>Your payment was successful. The window will now close.</p>
</body>
</html>
