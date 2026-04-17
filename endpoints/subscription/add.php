<?php
error_reporting(E_ERROR | E_PARSE);
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/inputvalidation.php';
require_once '../../includes/getsettings.php';

if (!file_exists('../../images/uploads/logos')) {
    mkdir('../../images/uploads/logos', 0777, true);
    mkdir('../../images/uploads/logos/avatars', 0777, true);
}

function sanitizeFilename($filename)
{
    $filename = preg_replace("/[^a-zA-Z0-9\s]/", "", $filename);
    $filename = str_replace(" ", "-", $filename);
    $filename = str_replace(".", "", $filename);
    return $filename;
}

function validateFileExtension($fileExtension)
{
    $allowedExtensions = ['png', 'jpg', 'jpeg', 'gif', 'webp'];
    return in_array($fileExtension, $allowedExtensions);
}

function getLogoFromUrl($url, $uploadDir, $name, $settings, $i18n)
{
    $maxRedirects = 3;
    $currentUrl = $url;

    for ($i = 0; $i <= $maxRedirects; $i++) {
        if (!filter_var($currentUrl, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $currentUrl)) {
            return ['success' => false, 'message' => 'Invalid URL format.'];
        }

        $parts = parse_url($currentUrl);
        $host = $parts['host'];
        $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
        $ip = gethostbyname($host);

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return ['success' => false, 'message' => 'Invalid IP Address.'];
        }

        $ch = curl_init($currentUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_RESOLVE, ["$host:$port:$ip"]);

        $imageData = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode >= 300 && $httpCode < 400) {
            $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            unset($ch);

            if (!$redirectUrl) {
                break;
            }

            $currentUrl = $redirectUrl;
            continue;
        }

        if ($imageData !== false && $httpCode === 200) {
            $timestamp = time();
            $fileName = $timestamp . '-' . sanitizeFilename($name) . '.png';
            $uploadFile = '../../images/uploads/logos/' . $fileName;

            if (saveLogo($imageData, $uploadFile, $name, $settings)) {
                unset($ch);
                return ['success' => true, 'filename' => $fileName];
            }
        }

        $error = curl_error($ch);
        unset($ch);
        return ['success' => false, 'message' => translate('error_fetching_image', $i18n) . ': ' . $error];
    }

    return ['success' => false, 'message' => translate('error_fetching_image', $i18n)];
}

function saveLogo($imageData, $uploadFile, $name, $settings)
{
    $image = imagecreatefromstring($imageData);
    $removeBackground = isset($settings['removeBackground']) && $settings['removeBackground'] === 'true';

    if ($image !== false) {
        $tempFile = tempnam(sys_get_temp_dir(), 'logo');

        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagepng($image, $tempFile);
        imagedestroy($image);

        if (extension_loaded('imagick')) {
            $imagick = new Imagick($tempFile);

            if ($removeBackground) {
                $imagick->setImageAlphaChannel(Imagick::ALPHACHANNEL_ACTIVATE);

                $pixel = $imagick->getImagePixelColor(0, 0);
                $color = $pixel->getColor();
                if ($color['a'] > 0) {
                    $bgColor = "rgb({$color['r']},{$color['g']},{$color['b']})";
                    $fuzz = Imagick::getQuantum() * 0.1;
                    $imagick->transparentPaintImage($bgColor, 0, $fuzz, false);
                }
            }

            $imagick->setImageFormat('png');
            $imagick->writeImage($uploadFile);
            $imagick->clear();
            $imagick->destroy();

        } else {
            $newImage = imagecreatefrompng($tempFile);
            if ($newImage !== false) {
                imagealphablending($newImage, false);
                imagesavealpha($newImage, true);

                if ($removeBackground) {
                    $transparent = imagecolorallocatealpha($newImage, 0, 0, 0, 127);
                    imagefill($newImage, 0, 0, $transparent);
                }

                imagepng($newImage, $uploadFile);
                imagedestroy($newImage);
            } else {
                unlink($tempFile);
                return false;
            }
        }

        unlink($tempFile);
        return true;
    }

    return false;
}

function resizeAndUploadLogo($uploadedFile, $uploadDir, $name, $settings)
{
    $targetWidth = 135;
    $targetHeight = 42;

    $timestamp = time();
    $originalFileName = $uploadedFile['name'];
    $fileExtension = pathinfo($originalFileName, PATHINFO_EXTENSION);
    $fileExtension = validateFileExtension($fileExtension) ? $fileExtension : 'png';
    $fileName = $timestamp . '-' . sanitizeFilename($name) . '.' . $fileExtension;
    $uploadFile = $uploadDir . $fileName;

    if (move_uploaded_file($uploadedFile['tmp_name'], $uploadFile)) {
        $fileInfo = getimagesize($uploadFile);

        if ($fileInfo !== false) {
            $width = $fileInfo[0];
            $height = $fileInfo[1];

            if ($fileExtension === 'png') {
                $image = imagecreatefrompng($uploadFile);
            } elseif ($fileExtension === 'jpg' || $fileExtension === 'jpeg') {
                $image = imagecreatefromjpeg($uploadFile);
            } elseif ($fileExtension === 'gif') {
                $image = imagecreatefromgif($uploadFile);
            } elseif ($fileExtension === 'webp') {
                $image = imagecreatefromwebp($uploadFile);
            } else {
                return "";
            }

            if ($fileExtension === 'png') {
                imagesavealpha($image, true);
            }

            $newWidth = $width;
            $newHeight = $height;

            if ($width > $targetWidth) {
                $newWidth = (int) $targetWidth;
                $newHeight = (int) (($targetWidth / $width) * $height);
            }

            if ($newHeight > $targetHeight) {
                $newWidth = (int) (($targetHeight / $newHeight) * $newWidth);
                $newHeight = (int) $targetHeight;
            }

            $resizedImage = imagecreatetruecolor($newWidth, $newHeight);
            imagesavealpha($resizedImage, true);
            $transparency = imagecolorallocatealpha($resizedImage, 0, 0, 0, 127);
            imagefill($resizedImage, 0, 0, $transparency);
            imagecopyresampled($resizedImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

            if ($fileExtension === 'png') {
                imagepng($resizedImage, $uploadFile);
            } elseif ($fileExtension === 'jpg' || $fileExtension === 'jpeg') {
                imagejpeg($resizedImage, $uploadFile);
            } elseif ($fileExtension === 'gif') {
                imagegif($resizedImage, $uploadFile);
            } elseif ($fileExtension === 'webp') {
                imagewebp($resizedImage, $uploadFile);
            } else {
                return "";
            }

            imagedestroy($image);
            imagedestroy($resizedImage);

            return $fileName;
        }
    }

    return "";
}

function getUserHouseholdIds($db, $userId)
{
    $householdIds = [];
    $query = "SELECT id FROM household WHERE user_id = :userId";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $householdIds[intval($row['id'])] = true;
    }

    return $householdIds;
}

function toCents($amount)
{
    return (int) round(floatval($amount) * 100);
}

function fromCents($cents)
{
    return round($cents / 100, 2);
}

function parseAndDistributeParticipants($participantsPayload, $price, $validHouseholdIds)
{
    $decoded = json_decode($participantsPayload, true);
    if (!is_array($decoded) || count($decoded) === 0) {
        return ['ok' => false, 'message' => 'Please select at least one participant.'];
    }

    $participants = [];
    foreach ($decoded as $rawParticipant) {
        $householdId = isset($rawParticipant['household_id']) ? intval($rawParticipant['household_id']) : 0;
        if ($householdId <= 0 || !isset($validHouseholdIds[$householdId])) {
            return ['ok' => false, 'message' => 'Invalid participant selected.'];
        }

        if (isset($participants[$householdId])) {
            return ['ok' => false, 'message' => 'Duplicate participant selected.'];
        }

        $isManual = !empty($rawParticipant['is_manual']);
        $manualCents = null;

        if ($isManual) {
            if (!isset($rawParticipant['amount']) || $rawParticipant['amount'] === '') {
                return ['ok' => false, 'message' => 'Manual participant amount is required.'];
            }

            if (!is_numeric($rawParticipant['amount'])) {
                return ['ok' => false, 'message' => 'Manual participant amount is invalid.'];
            }

            $manualCents = toCents($rawParticipant['amount']);
            if ($manualCents < 0) {
                return ['ok' => false, 'message' => 'Manual participant amount cannot be negative.'];
            }
        }

        $participants[$householdId] = [
            'household_id' => $householdId,
            'is_manual' => $isManual ? 1 : 0,
            'amount_cents' => $manualCents
        ];
    }

    $totalCents = toCents($price);
    if ($totalCents < 0) {
        return ['ok' => false, 'message' => 'Subscription price cannot be negative.'];
    }

    $manualSumCents = 0;
    $autoParticipantIds = [];
    foreach ($participants as $participant) {
        if ($participant['is_manual'] === 1) {
            $manualSumCents += $participant['amount_cents'];
        } else {
            $autoParticipantIds[] = $participant['household_id'];
        }
    }

    if ($manualSumCents > $totalCents) {
        return ['ok' => false, 'message' => 'Manual amounts exceed the total subscription price.'];
    }

    $remainingCents = $totalCents - $manualSumCents;
    $autoCount = count($autoParticipantIds);

    if ($autoCount === 0 && $remainingCents !== 0) {
        return ['ok' => false, 'message' => 'Participant amounts must match the total subscription price.'];
    }

    if ($autoCount > 0) {
        $baseCents = intdiv($remainingCents, $autoCount);
        $remainderCents = $remainingCents % $autoCount;

        foreach ($autoParticipantIds as $idx => $participantId) {
            $participants[$participantId]['amount_cents'] = $baseCents + ($idx < $remainderCents ? 1 : 0);
        }
    }

    $finalSum = 0;
    $finalParticipants = [];
    foreach ($participants as $participant) {
        $finalSum += $participant['amount_cents'];
        $finalParticipants[] = [
            'household_id' => $participant['household_id'],
            'amount' => fromCents($participant['amount_cents']),
            'is_manual' => $participant['is_manual']
        ];
    }

    if ($finalSum !== $totalCents) {
        return ['ok' => false, 'message' => 'Participant amounts must match the total subscription price.'];
    }

    return ['ok' => true, 'participants' => $finalParticipants];
}

$isEdit = isset($_POST['id']) && $_POST['id'] != "";
$name = validate($_POST["name"]);
$price = $_POST['price'];
$currencyId = $_POST["currency_id"];
$frequency = $_POST["frequency"];
$cycle = $_POST["cycle"];
$nextPayment = $_POST["next_payment"];
$autoRenew = isset($_POST['auto_renew']) ? true : false;
$startDate = $_POST["start_date"];
$paymentMethodId = $_POST["payment_method_id"];
$payerUserId = $_POST["payer_user_id"];
$categoryId = $_POST['category_id'];
$notes = validate($_POST["notes"]);
$url = validate($_POST['url']);
$logoUrl = validate($_POST['logo-url']);
$logo = "";
$logoError = "";
$notify = isset($_POST['notifications']) ? true : false;
$notifyDaysBefore = $_POST['notify_days_before'];
$inactive = isset($_POST['inactive']) ? true : false;
$cancellationDate = $_POST['cancellation_date'] ?? null;
$replacementSubscriptionId = $_POST['replacement_subscription_id'];
$participantsPayload = $_POST['participants_payload'] ?? '';

if ($replacementSubscriptionId == 0 || $inactive == 0) {
    $replacementSubscriptionId = null;
}

$participantsTableExists = $db
    ->query("SELECT name FROM sqlite_master WHERE type='table' AND name='subscription_participants'")
    ->fetchArray(SQLITE3_ASSOC) !== false;

if (!$participantsTableExists) {
    echo json_encode([
        'status' => 'Error',
        'message' => 'Database migration missing. Please run database migrations first.'
    ]);
    exit();
}

$validHouseholdIds = getUserHouseholdIds($db, $userId);
$parsedParticipants = parseAndDistributeParticipants($participantsPayload, $price, $validHouseholdIds);
if (!$parsedParticipants['ok']) {
    echo json_encode([
        'status' => 'Error',
        'message' => $parsedParticipants['message']
    ]);
    exit();
}

if ($logoUrl !== "") {
    $result = getLogoFromUrl($logoUrl, '../../images/uploads/logos/', $name, $settings, $i18n);
    if ($result['success']) {
        $logo = $result['filename'];
    } else {
        $logoError = $result['message'];
    }
} else {
    if (!empty($_FILES['logo']['name'])) {
        $fileType = mime_content_type($_FILES['logo']['tmp_name']);
        if (strpos($fileType, 'image') === false) {
            echo translate("fill_all_fields", $i18n);
            exit();
        }
        $logo = resizeAndUploadLogo($_FILES['logo'], '../../images/uploads/logos/', $name, $settings);
    }
}

if (!$isEdit) {
    $sql = "INSERT INTO subscriptions (
                        name, logo, price, currency_id, next_payment, cycle, frequency, notes, 
                        payment_method_id, payer_user_id, category_id, notify, inactive, url, 
                        notify_days_before, user_id, cancellation_date, replacement_subscription_id,
                        auto_renew, start_date
                    ) VALUES (
                        :name, :logo, :price, :currencyId, :nextPayment, :cycle, :frequency, :notes, 
                        :paymentMethodId, :payerUserId, :categoryId, :notify, :inactive, :url, 
                        :notifyDaysBefore, :userId, :cancellationDate, :replacement_subscription_id,
                        :autoRenew, :startDate
                    )";
} else {
    $id = $_POST['id'];
    $sql = "UPDATE subscriptions SET 
                        name = :name, 
                        price = :price, 
                        currency_id = :currencyId,
                        next_payment = :nextPayment, 
                        auto_renew = :autoRenew,
                        start_date = :startDate,
                        cycle = :cycle, 
                        frequency = :frequency, 
                        notes = :notes, 
                        payment_method_id = :paymentMethodId,
                        payer_user_id = :payerUserId, 
                        category_id = :categoryId, 
                        notify = :notify, 
                        inactive = :inactive, 
                        url = :url, 
                        notify_days_before = :notifyDaysBefore, 
                        cancellation_date = :cancellationDate, 
                        replacement_subscription_id = :replacement_subscription_id";

    if ($logo != "") {
        $sql .= ", logo = :logo";
    }

    $sql .= " WHERE id = :id AND user_id = :userId";
}

$db->exec('BEGIN');
$stmt = $db->prepare($sql);
$stmt->bindParam(':name', $name, SQLITE3_TEXT);
if ($logo != "") {
    $stmt->bindParam(':logo', $logo, SQLITE3_TEXT);
}
$stmt->bindParam(':price', $price, SQLITE3_FLOAT);
$stmt->bindParam(':currencyId', $currencyId, SQLITE3_INTEGER);
$stmt->bindParam(':nextPayment', $nextPayment, SQLITE3_TEXT);
$stmt->bindParam(':autoRenew', $autoRenew, SQLITE3_INTEGER);
$stmt->bindParam(':startDate', $startDate, SQLITE3_TEXT);
$stmt->bindParam(':cycle', $cycle, SQLITE3_INTEGER);
$stmt->bindParam(':frequency', $frequency, SQLITE3_INTEGER);
$stmt->bindParam(':notes', $notes, SQLITE3_TEXT);
$stmt->bindParam(':paymentMethodId', $paymentMethodId, SQLITE3_INTEGER);
$stmt->bindParam(':payerUserId', $payerUserId, SQLITE3_INTEGER);
$stmt->bindParam(':categoryId', $categoryId, SQLITE3_INTEGER);
$stmt->bindParam(':notify', $notify, SQLITE3_INTEGER);
$stmt->bindParam(':inactive', $inactive, SQLITE3_INTEGER);
$stmt->bindParam(':url', $url, SQLITE3_TEXT);
$stmt->bindParam(':notifyDaysBefore', $notifyDaysBefore, SQLITE3_INTEGER);
$stmt->bindParam(':cancellationDate', $cancellationDate, SQLITE3_TEXT);
if ($isEdit) {
    $stmt->bindParam(':id', $id, SQLITE3_INTEGER);
}
$stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
$stmt->bindParam(':replacement_subscription_id', $replacementSubscriptionId, SQLITE3_INTEGER);

if ($stmt->execute()) {
    $subscriptionId = $isEdit ? intval($id) : intval($db->lastInsertRowID());
    $deleteParticipantsStmt = $db->prepare("DELETE FROM subscription_participants WHERE subscription_id = :subscriptionId");
    $deleteParticipantsStmt->bindValue(':subscriptionId', $subscriptionId, SQLITE3_INTEGER);
    $deleteParticipantsStmt->execute();

    $insertParticipantSql = "INSERT INTO subscription_participants (subscription_id, household_id, amount, is_manual)
                             VALUES (:subscriptionId, :householdId, :amount, :isManual)";
    $insertParticipantStmt = $db->prepare($insertParticipantSql);

    foreach ($parsedParticipants['participants'] as $participant) {
        $insertParticipantStmt->bindValue(':subscriptionId', $subscriptionId, SQLITE3_INTEGER);
        $insertParticipantStmt->bindValue(':householdId', $participant['household_id'], SQLITE3_INTEGER);
        $insertParticipantStmt->bindValue(':amount', $participant['amount'], SQLITE3_FLOAT);
        $insertParticipantStmt->bindValue(':isManual', $participant['is_manual'], SQLITE3_INTEGER);
        if (!$insertParticipantStmt->execute()) {
            $db->exec('ROLLBACK');
            echo json_encode([
                'status' => 'Error',
                'message' => translate('error', $i18n) . ': ' . $db->lastErrorMsg()
            ]);
            exit();
        }
    }

    $db->exec('COMMIT');
    $success['status'] = "Success";
    $text = $isEdit ? "updated" : "added";
    $success['message'] = translate('subscription_' . $text . '_successfuly', $i18n);
    if ($logoError !== "") {
        $success['logo_warning'] = $logoError;
    }
    header('Content-Type: application/json');
    echo json_encode($success);
    exit();
} else {
    $db->exec('ROLLBACK');
    echo translate('error', $i18n) . ": " . $db->lastErrorMsg();
}
$db->close();
?>