<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$driver = require_driver();
$driverId = (int) $driver['id'];

$profileQuery = db()->prepare(
    'SELECT full_name, email, phone, license_number
     FROM drivers WHERE id = :id LIMIT 1'
);
$profileQuery->execute(['id' => $driverId]);
$profile = $profileQuery->fetch();
if ($profile === false) {
    http_response_code(404);
    exit('Driver account not found.');
}

$errors = [];
$notice = '';
$email = (string) $profile['email'];
$phone = (string) $profile['phone'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();

    if (($_POST['action'] ?? '') === 'profile_photo') {
        $upload = $_FILES['profile_photo'] ?? null;
        if (!is_array($upload) || !isset($upload['error'], $upload['tmp_name'], $upload['size'])
            || !is_int($upload['error']) || !is_string($upload['tmp_name']) || !is_int($upload['size'])) {
            $errors['photo'] = 'Choose a photo to upload.';
        } elseif ($upload['error'] !== UPLOAD_ERR_OK) {
            $errors['photo'] = match ($upload['error']) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The photo must be 5 MB or smaller.',
                UPLOAD_ERR_NO_FILE => 'Choose a photo to upload.',
                default => 'The photo could not be uploaded. Please try again.',
            };
        } elseif ($upload['size'] < 1 || $upload['size'] > 5 * 1024 * 1024 || !is_uploaded_file($upload['tmp_name'])) {
            $errors['photo'] = 'Choose a valid photo that is 5 MB or smaller.';
        } else {
            $fileInfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $fileInfo->file($upload['tmp_name']);
            $imageInfo = @getimagesize($upload['tmp_name']);
            $extensions = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
            ];
            if (!is_string($mimeType) || !isset($extensions[$mimeType])
                || !is_array($imageInfo) || ($imageInfo['mime'] ?? null) !== $mimeType
                || !isset($imageInfo[0], $imageInfo[1])
                || $imageInfo[0] > 6000 || $imageInfo[1] > 6000
                || $imageInfo[0] * $imageInfo[1] > 16000000) {
                $errors['photo'] = 'Use a JPEG, PNG, or WebP image up to 5 MB.';
            } else {
                if (strtolower(environment_value('PROFILE_PHOTO_STORAGE', 'filesystem')) === 'database') {
                    $imageContents = file_get_contents($upload['tmp_name']);
                    if ($imageContents === false || $imageContents === '') {
                        throw new RuntimeException('Unable to read the uploaded driver profile photo.');
                    }
                    $savePhoto = db()->prepare(
                        'INSERT INTO profile_photos (driver_id, mime_type, image_data)
                         VALUES (:driver_id, :mime_type, :image_data)
                         ON DUPLICATE KEY UPDATE mime_type = VALUES(mime_type), image_data = VALUES(image_data)'
                    );
                    $savePhoto->bindValue(':driver_id', $driverId, PDO::PARAM_INT);
                    $savePhoto->bindValue(':mime_type', $mimeType, PDO::PARAM_STR);
                    $savePhoto->bindValue(':image_data', $imageContents, PDO::PARAM_LOB);
                    $savePhoto->execute();
                } else {
                    $photoDirectory = dirname(__DIR__) . '/storage/driver-photos';
                    $photoName = $driverId . '-' . bin2hex(random_bytes(16)) . '.' . $extensions[$mimeType];
                    $photoPath = $photoDirectory . DIRECTORY_SEPARATOR . $photoName;
                    if (!move_uploaded_file($upload['tmp_name'], $photoPath)) {
                        throw new RuntimeException('Unable to store the uploaded driver profile photo.');
                    }

                    $oldPhotos = glob($photoDirectory . DIRECTORY_SEPARATOR . $driverId . '-*');
                    if ($oldPhotos !== false) {
                        foreach ($oldPhotos as $oldPhoto) {
                            if ($oldPhoto !== $photoPath && is_file($oldPhoto)
                                && preg_match('/\A' . $driverId . '-[a-f0-9]{32}\.(?:jpg|png|webp)\z/', basename($oldPhoto)) === 1
                                && !unlink($oldPhoto)) {
                                error_log('Unable to remove a replaced driver profile photo.');
                            }
                        }
                    }
                }
                $_SESSION['driver_profile_notice'] = 'Your profile photo was updated.';
                redirect('driver-profile.php');
            }
        }
    } else {
        $emailInput = $_POST['email'] ?? '';
        $phoneInput = $_POST['phone'] ?? '';
        $email = is_string($emailInput) ? strtolower(trim($emailInput)) : '';
        $phone = is_string($phoneInput) ? trim($phoneInput) : '';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            $errors['email'] = 'Enter a valid email address (up to 254 characters).';
        }

        $phoneDigits = preg_replace('/\D/', '', $phone);
        if (
            strlen($phone) > 32 ||
            preg_match('/^\+?[0-9][0-9 ().-]*$/', $phone) !== 1 ||
            !is_string($phoneDigits) ||
            strlen($phoneDigits) < 7 ||
            strlen($phoneDigits) > 15
        ) {
            $errors['phone'] = 'Enter a phone number with 7 to 15 digits; spaces, +, parentheses, and hyphens are allowed.';
        }

        if ($errors === []) {
            $update = db()->prepare(
                'UPDATE drivers SET email = :email, phone = :phone WHERE id = :id'
            );
            try {
                $update->execute([
                    'email' => $email,
                    'phone' => $phone,
                    'id' => $driverId,
                ]);
                $_SESSION['driver']['email'] = $email;
                $profile['email'] = $email;
                $profile['phone'] = $phone;
                $notice = 'Your contact information was updated.';
            } catch (PDOException $exception) {
                if ($exception->getCode() !== '23000') {
                    throw $exception;
                }
                $errors['email'] = 'That email address is already associated with another account.';
            }
        }
    }
}

$notice = (string) ($_SESSION['driver_profile_notice'] ?? $notice);
unset($_SESSION['driver_profile_notice']);
if (strtolower(environment_value('PROFILE_PHOTO_STORAGE', 'filesystem')) === 'database') {
    $photoVersion = driver_photo_database_version($driverId);
    $profilePhotoUrl = $photoVersion === null
        ? null
        : 'admin-profile-photo.php?v=' . rawurlencode($photoVersion);
} else {
    $profilePhoto = driver_photo_file($driverId);
    $profilePhotoUrl = $profilePhoto === null
        ? null
        : 'driver-photo.php?v=' . rawurlencode((string) filemtime($profilePhoto));
}

page_start('My profile', 'driver-profile');
?>
<section class="driver-profile-page">
  <div class="driver-profile-heading">
    <div>
      <span class="eyebrow"><span class="eyebrow-dot"></span> YOUR ACCOUNT</span>
      <h1>Contact information</h1>
      <p>Keep your account contact details current.</p>
    </div>
    <span class="profile-secure-badge"><span aria-hidden="true"><svg class="ui-icon" aria-hidden="true" focusable="false" width="24" height="24"><use href="assets/icons.svg#shield"></use></svg></span> Private account details</span>
  </div>

  <?php if ($notice !== ''): ?><p class="message message-success profile-message" role="status"><span aria-hidden="true"><svg class="ui-icon" aria-hidden="true" focusable="false" width="24" height="24"><use href="assets/icons.svg#check"></use></svg></span> <?= h($notice) ?></p><?php endif; ?>
  <?php if ($errors !== []): ?><p class="message message-error profile-message" role="alert"><?= isset($errors['photo']) ? 'Please choose a valid profile photo.' : 'Please review the highlighted contact information.' ?></p><?php endif; ?>

  <div class="driver-profile-layout">
    <section class="driver-profile-card profile-photo-card" aria-labelledby="profile-photo-title">
      <div class="driver-profile-card-heading">
        <span class="profile-photo-preview" aria-hidden="true">
          <?php if ($profilePhotoUrl !== null): ?><img src="<?= h($profilePhotoUrl) ?>" alt="">
          <?php else: ?><?= h(account_initial((string) $profile['full_name'])) ?><?php endif; ?>
        </span>
        <div><h2 id="profile-photo-title">Profile photo</h2><p>Upload a photo to personalize your driver account.</p></div>
      </div>
      <?php if (isset($errors['photo'])): ?><p class="vehicle-field-error" role="alert"><?= h($errors['photo']) ?></p><?php endif; ?>
      <form class="profile-photo-form" method="post" action="driver-profile.php" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="profile_photo">
        <input type="hidden" name="MAX_FILE_SIZE" value="5242880">
        <label for="profile-photo-upload">Choose a photo</label>
        <input id="profile-photo-upload" name="profile_photo" type="file" accept="image/jpeg,image/png,image/webp" required>
        <p>JPEG, PNG, or WebP. Maximum file size: 5 MB.</p>
        <button class="button button-outline" type="submit">Upload photo</button>
        <p class="profile-photo-preview-status" id="profile-photo-preview-status" role="status" aria-live="polite"></p>
      </form>
    </section>

    <section class="driver-profile-card">
      <div class="driver-profile-card-heading">
        <span class="driver-profile-icon" aria-hidden="true"><svg class="ui-icon" aria-hidden="true" focusable="false" width="24" height="24"><use href="assets/icons.svg#user"></use></svg></span>
        <div><h2>Authorized contact details</h2><p>Only your own email and phone number can be changed here.</p></div>
      </div>
      <form class="form-stack driver-profile-form" method="post" action="driver-profile.php" autocomplete="on">
        <?= csrf_field() ?>
        <label>Email address
          <input type="email" name="email" required maxlength="254" autocomplete="email" value="<?= h($email) ?>" aria-invalid="<?= isset($errors['email']) ? 'true' : 'false' ?>" aria-describedby="profile-email-error">
          <?php if (isset($errors['email'])): ?><span class="vehicle-field-error" id="profile-email-error"><?= h($errors['email']) ?></span><?php endif; ?>
        </label>
        <label>Phone number
          <input type="tel" name="phone" required maxlength="32" autocomplete="tel" value="<?= h($phone) ?>" placeholder="+63 9XX XXX XXXX" aria-invalid="<?= isset($errors['phone']) ? 'true' : 'false' ?>" aria-describedby="profile-phone-hint profile-phone-error">
          <span class="vehicle-field-hint" id="profile-phone-hint">Use 7 to 15 digits. International prefix and common separators are accepted.</span>
          <?php if (isset($errors['phone'])): ?><span class="vehicle-field-error" id="profile-phone-error"><?= h($errors['phone']) ?></span><?php endif; ?>
        </label>
        <button class="button button-primary profile-submit" type="submit">Save contact details <span aria-hidden="true"><svg class="ui-icon" aria-hidden="true" focusable="false" width="24" height="24"><use href="assets/icons.svg#arrow"></use></svg></span></button>
      </form>
    </section>

    <aside class="driver-profile-card profile-readonly-card">
      <div class="driver-profile-card-heading">
        <span class="driver-profile-icon profile-readonly-icon" aria-hidden="true"><svg class="ui-icon" aria-hidden="true" focusable="false" width="24" height="24"><use href="assets/icons.svg#check"></use></svg></span>
        <div><h2>Verified account details</h2><p>These details are not editable on this page.</p></div>
      </div>
      <dl class="profile-readonly-list">
        <div><dt>Full name</dt><dd><?= h((string) $profile['full_name']) ?></dd></div>
        <div><dt>Driver license number</dt><dd><?= h((string) $profile['license_number']) ?></dd></div>
      </dl>
      <p class="profile-security-note">Your changes are saved only to your signed-in driver account. Your login email changes when you update the email address above.</p>
    </aside>
  </div>
</section>
<script src="assets/driver-profile-photo.js?v=<?= h((string) filemtime(__DIR__ . '/assets/driver-profile-photo.js')) ?>" defer></script>
<?php page_end(); ?>
