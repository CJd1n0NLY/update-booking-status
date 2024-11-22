<?php
header('Content-Type: application/json');

use App\Core\Database;
require_once __DIR__ . '/../../../vendor/autoload.php';

$db = new Database();
$connection = $db->connect();

try {
    $data = json_decode(file_get_contents('php://input'), true);

    if (isset($data['booking_id']) && isset($data['status'])) {
        $bookingId = (int)$data['booking_id'];
        $newStatus = $data['status'];

        if (in_array($newStatus, ['pending', 'confirmed', 'cancelled'])) {
            $queryServiceTime = "
                SELECT 
                    s.service_processing_time, 
                    s.service_preparation_time, 
                    s.service_buffer_time,
                    s.service_price,
                    s.service_name, 
                    b.booking_date,
                    b.cus_fname,
                    b.cus_email,
                    b.cus_phone
                FROM tbl_booking b
                INNER JOIN tbl_services s ON b.service_id = s.service_id
                WHERE b.booking_id = ?
            ";
            $stmt = $connection->prepare($queryServiceTime);
            $stmt->bind_param("i", $bookingId);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                echo json_encode(['success' => false, 'message' => 'Booking not found.']);
                exit;
            }

            $row = $result->fetch_assoc();
            $processingTime = $row['service_processing_time'];
            $preparationTime = $row['service_preparation_time'];
            $bufferTime = $row['service_buffer_time'];
            $date = $row['booking_date'];
            $customerName = $row['cus_fname'];
            $customerEmail = $row['cus_email'];
            $servicePrice = $row['service_price'];
            $serviceName = $row['service_name'];
            $cusPhone = $row['cus_phone'];

            $bookingDate = new DateTime($date);
            $prepStartDate = clone $bookingDate;
            $prepStartDate->sub(new DateInterval("P{$preparationTime}D"));
            $processEndDate = clone $bookingDate;
            $processEndDate->add(new DateInterval("P{$processingTime}D"));
            $bufferEndDate = clone $processEndDate;
            $bufferEndDate->add(new DateInterval("P{$bufferTime}D"));

            $overlapQuery = $connection->prepare("
                SELECT tbl_booking.booking_date, tbl_services.service_processing_time, tbl_services.service_preparation_time, tbl_services.service_buffer_time
                FROM tbl_booking
                JOIN tbl_services ON tbl_booking.service_id = tbl_services.service_id
                WHERE (
                    tbl_booking.booking_date BETWEEN ? AND ? 
                    OR DATE_ADD(tbl_booking.booking_date, INTERVAL tbl_services.service_processing_time DAY) BETWEEN ? AND ? 
                    OR DATE_ADD(tbl_booking.booking_date, INTERVAL tbl_services.service_buffer_time DAY) BETWEEN ? AND ?
                ) AND tbl_booking.booking_id != ? AND tbl_booking.status = 'confirmed'
            ");

            $start_date = $prepStartDate->format('Y-m-d');
            $end_date = $bufferEndDate->format('Y-m-d');

            $overlapQuery->bind_param("sssssss", $start_date, $end_date, $start_date, $end_date, $start_date, $end_date, $bookingId);
            $overlapQuery->execute();
            $overlapResult = $overlapQuery->get_result();

            if ($overlapResult->num_rows > 0) {
                $autoCancelQuery = "UPDATE tbl_booking SET status = 'cancelled' WHERE booking_id = ?";
                $autoCancelUpdate = $connection->prepare($autoCancelQuery);
                $autoCancelUpdate->bind_param("i", $bookingId);
                $autoCancelUpdate->execute();
                echo json_encode(['success' => false, 'message' => 'Conflict detected with another booking. This booking will now be automatically cancelled']);
            } else {
                $queryUpdate = "UPDATE tbl_booking SET status = ? WHERE booking_id = ?";
                $stmtUpdate = $connection->prepare($queryUpdate);
                $stmtUpdate->bind_param("si", $newStatus, $bookingId);

                if ($stmtUpdate->execute()) {
                    echo json_encode(['success' => true]);

                    $mail = require __DIR__ . '/../../../resource/view/auth/mailer.php';
                    try {
                        $mail->setFrom('ec-clean@gmail.com', 'Ec-Clean Water Systems');
                        $mail->addAddress($customerEmail, $customerName);
                        $mail->isHTML(true); 
                        if ($newStatus === 'confirmed') {
                            $apiKey = "sk_test_G2z2SxwfToRvxuPiXZ975Nqm";
                            $amount = $servicePrice * 0.25;
                            $description = "Booking #{$bookingId}";
                            $paymentLink = null;
                            $response = createPayMongoCheckoutLink($amount, $description, $customerName, $customerEmail, $cusPhone, $serviceName, $apiKey, $bookingId);

                            if ($response !== null) {
                                $paymentLink = $response;   
                            } else {
                                echo "Failed to create payment link due to an API issue.";
                            }

                            $mail->Subject = 'Booking Confirmation';
                            $mail->addEmbeddedImage(__DIR__ . '/../../.././public/image/ec-logonamin.png', 'logo_image_cid');
                            $mail->addEmbeddedImage(__DIR__ . '/../../.././public/image/thankyouu.gif', 'thankyou_gif_cid');
                            $mail->Body = 
                                            "<html>
                                                <head>
                                                    <style>
                                                        @import url('https://fonts.googleapis.com/css2?family=Michroma&display=swap');
                
                                                        body {
                                                            font-family: Michroma, sans-serif;
                                                            color: #333;
                                                        }
                                                        .email-container {
                                                            max-width: 600px;
                                                            margin: auto;
                                                            padding: 20px;
                                                            background-color: #f9f9f9;
                                                            border-radius: 10px;
                                                            box-shadow: 0px 0px 10px rgba(0, 0, 0, 0.1);
                                                        }
                                                        .header {
                                                            text-align: center;
                                                        }
                                                        .header img {
                                                            width: 200px;
                                                            margin-bottom: 10px;
                                                        }
                                                        .greeting {
                                                            font-weight: bold;
                                                            font-size: 18px;
                                                            color: #57b4d8;
                                                        }
                                                        .content {
                                                            font-size: 16px;
                                                            line-height: 1.6;
                                                        }
                                                        .otp-code {
                                                            font-size: 24px;
                                                            font-weight: bold;
                                                            color: #57b4d8;
                                                            text-align: center;
                                                            margin: 20px 0;
                                                        }
                                                        .footer {
                                                            font-size: 14px;
                                                            text-align: center;
                                                            color: #777;
                                                            margin-top: 30px;
                                                        }
                                                        .footer img {
                                                            width: 300px;
                                                            margin-bottom: 10px;
                                                        }
                
                                                    </style>
                                                </head>
                                                <body>
                                                    <div class='email-container'>
                                                        <div class='header'>
                                                            <img src='cid:logo_image_cid' alt='EC-Clean Water Systems Logo'>
                                                            <h2>Ec-Clean Water Systems</h2>
                                                        </div>
                                                        <div class='content'>
                                                            <p class='greeting'>Hey $customerName!</p>
                                                            <p>Your booking has been confirmed. Please complete your payment using the link below:</p>";

                                                            if (!empty($paymentLink)) {
                                                                $mail->Body .= "<div class='otp-code'><a href='$paymentLink'>$paymentLink</a></div>";
                                                            } else {
                                                                $mail->Body .= "<div class='otp-code'>Payment link is unavailable at this time. Please contact support.</div>";
                                                            }
                                                        $mail->Body .= "
                                                        </div>
                                                        <div class='footer'>
                                                            <p>Thank you,<br>The OTOG Team</p>
                                                            <img src='cid:thankyou_gif_cid' alt='Thank you'>
                                                        </div>
                                                    </div>
                                                </body>
                                            </html>";
                        } else {
                            $mail->Subject = 'Booking Rejected';
                                $mail->addEmbeddedImage(__DIR__ . '/../../.././public/image/ec-logonamin.png', 'logo_image_cid');
                                $mail->addEmbeddedImage(__DIR__ . '/../../.././public/image/thankyouu.gif', 'thankyou_gif_cid');
                                $mail->Body = 
                                                "<html>
                                                    <head>
                                                        <style>
                                                            @import url('https://fonts.googleapis.com/css2?family=Michroma&display=swap');
                    
                                                            body {
                                                                font-family: Michroma, sans-serif;
                                                                color: #333;
                                                            }
                                                            .email-container {
                                                                max-width: 600px;
                                                                margin: auto;
                                                                padding: 20px;
                                                                background-color: #f9f9f9;
                                                                border-radius: 10px;
                                                                box-shadow: 0px 0px 10px rgba(0, 0, 0, 0.1);
                                                            }
                                                            .header {
                                                                text-align: center;
                                                            }
                                                            .header img {
                                                                width: 200px;
                                                                margin-bottom: 10px;
                                                            }
                                                            .greeting {
                                                                font-weight: bold;
                                                                font-size: 18px;
                                                                color: #57b4d8;
                                                            }
                                                            .content {
                                                                font-size: 16px;
                                                                line-height: 1.6;
                                                            }
                                                            .otp-code {
                                                                font-size: 24px;
                                                                font-weight: bold;
                                                                color: #57b4d8;
                                                                text-align: center;
                                                                margin: 20px 0;
                                                            }
                                                            .footer {
                                                                font-size: 14px;
                                                                text-align: center;
                                                                color: #777;
                                                                margin-top: 30px;
                                                            }
                                                            .footer img {
                                                                width: 300px;
                                                                margin-bottom: 10px;
                                                            }
                    
                                                        </style>
                                                    </head>
                                                    <body>
                                                        <div class='email-container'>
                                                            <div class='header'>
                                                                <img src='cid:logo_image_cid' alt='EC-Clean Water Systems Logo'>
                                                                <h2>Ec-Clean Water Systems</h2>
                                                            </div>
                                                            <div class='content'>
                                                                <p class='greeting'>Hi $customerName,</p>
                                                                <p>We regret to inform you that your booking has been rejected. Please contact us for further details.</p>
                                                            </div>
                                                            <div class='footer'>
                                                                <p>Thank you,<br>The OTOG Team</p>
                                                                <img src='cid:thankyou_gif_cid' alt='Thank you'>
                                                            </div>
                                                        </div>
                                                    </body>
                                                </html>";
                        }

                        if (!$mail->send()) {
                            throw new \Exception('Email could not be sent. Mailer Error: ' . $mail->ErrorInfo);
                        }
                        return true;
                    } catch (\Exception $e) {       
                        error_log($e->getMessage());
                        echo json_encode(['success' => false, 'message' => 'Email sending failed.']);
                        return false;
                    }
                } else {
                    echo json_encode(['success' => false, 'message' => 'Database update failed.']);
                }
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid status provided.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid input data.']);
    }
} catch (Exception $e) {
    error_log("Error occurred while processing booking ID $bookingId: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
}

function createPayMongoCheckoutLink($amount, $description, $customerName, $customerEmail, $customerPhone, $serviceName, $apiKey, $bookingId) {

    $url = "https://api.paymongo.com/v1/checkout_sessions";
    $data = [
        'data' => [
            'attributes' => [
                'billing' => [
                    'name' => $customerName,
                    'email' => $customerEmail,
                    'phone' => $customerPhone   
                ],
                'send_email_receipt' => true,
                'description' => $description,
                'line_items' => [
                    [
                        'currency' => 'PHP',
                        'amount' => $amount * 100,
                        'description' => $serviceName,  
                        'name' => $serviceName,     
                        'quantity' => 1
                    ]
                ],
                'payment_method_types' => ['gcash'],
                'metadata' => [
                'booking_id' => $bookingId
            ]
            ]
        ]
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'accept: application/json',
            'authorization: Basic ' . base64_encode("$apiKey:"),
        ]
    ]);

    $response = curl_exec($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($statusCode === 200) {
        $responseData = json_decode($response, true);
        return $responseData['data']['attributes']['checkout_url'] ?? null;
    } else {
        return null;
    }
}
?>
