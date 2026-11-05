<footer class="site-footer<?= ($showLandingContactDetails ?? false) ? ' landing-site-footer' : '' ?>">
    <?php if ($showLandingContactDetails ?? false): ?>
        <div class="landing-footer-content">
            <div class="landing-footer-brand">
                <p class="landing-footer-name">Angels Bridal Rental Shop</p>
                <p class="landing-footer-tagline">Making your special moments beautiful, one outfit at a time.</p>
            </div>
            <div class="landing-footer-details" aria-label="Shop contact information">
                <section>
                    <span class="landing-footer-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" focusable="false">
                            <path d="M12 21s7-6.2 7-12a7 7 0 1 0-14 0c0 5.8 7 12 7 12Z"></path>
                            <circle cx="12" cy="9" r="2.4"></circle>
                        </svg>
                    </span>
                    <h2>Visit us</h2>
                    <address>Aguinaldo St, Iligan City,<br>Lanao del Norte</address>
                </section>
                <section>
                    <span class="landing-footer-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" focusable="false">
                            <path d="M5.5 3.5h3l1.6 4.1-2 1.5a15 15 0 0 0 6.8 6.8l1.5-2 4.1 1.6v3a2 2 0 0 1-2.2 2A17.5 17.5 0 0 1 3.5 5.7a2 2 0 0 1 2-2.2Z"></path>
                        </svg>
                    </span>
                    <h2>Contact us</h2>
                    <p>09XX-XXX-XXXX</p>
                </section>
                <section>
                    <span class="landing-footer-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" focusable="false">
                            <path d="M3.5 5.5 9 3l6 2.5L20.5 3v15.5L15 21l-6-2.5-5.5 2.5V5.5Z"></path>
                            <path d="M9 3v15.5M15 5.5V21"></path>
                        </svg>
                    </span>
                    <h2>Service area</h2>
                    <p>We currently serve customers within Iligan City only.</p>
                </section>
            </div>
        </div>
    <?php endif; ?>
    <?php if ($showLandingContactDetails ?? false): ?>
        <p class="footer-copyright">© <?= date('Y') ?> Angels Bridal Rental Shop. All rights reserved.</p>
    <?php else: ?>
        © <?= date('Y') ?> Angels Bridal Rental Shop. All rights reserved.
    <?php endif; ?>
</footer>
<?php
$notificationScriptPath = preg_match(
    '~/(?:admin|auth|billing|costumer|staff)/[^/]+$~',
    str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''))
) ? '../javascript/system-notifications.js' : 'javascript/system-notifications.js';
$notificationScriptVersion = (string) filemtime(__DIR__ . '/../javascript/system-notifications.js');
$accountNotificationScriptVersion = (string) filemtime(__DIR__ . '/../javascript/account-notifications.js');
?>
<script src="<?= $notificationScriptPath ?>?v=<?= escape_html($notificationScriptVersion) ?>" defer></script>
<script src="<?= $notificationScriptPath === '../javascript/system-notifications.js' ? '../javascript/account-notifications.js' : 'javascript/account-notifications.js' ?>?v=<?= escape_html($accountNotificationScriptVersion) ?>" defer></script>
