# Angels Bridal Rental Shop

The storefront homepage shows products from the database with collection filters, search, public heart counts, and signed-in favourite controls. Favourites are saved per registered account; visitors can see counts but must sign in to save a product. Customers can add multiple products to a reservation list, choose one shared pickup and return date for all selected products, choose cash or PayPal, then confirm the selected items as one booking session. Each product remains a separate reservation for QR pickup/return, fees, and payment records, while the customer history groups items from one confirmation together. Reservation dates and application/database timestamps use Philippines time (`Asia/Manila`, UTC+08:00). An item added to a list is held for that customer for up to three days and cannot be added by another customer; removing it, confirming it, or letting the hold expire releases it. Customers can cancel pending unpaid booking sessions (legacy single reservations are cancelled individually) and view their rental and payment history. An unpaid request expires after three days and overlapping active reservations are rejected.

Cash booking sessions are accepted by staff as a group (legacy single reservations remain individually accepted); online sessions go directly to one PayPal checkout for all rental charges and refundable deposits. A successful PayPal checkout returns a receipt and automatically accepts the session. Staff scan each product QR tag and verify the customer before confirming each pickup; the scan view shows the paid status. Staff scan the same product tag on return, inspect it, and record damage and late-return charges. With the customer's recorded authorization, eligible charges are deducted from the refundable deposit first; any remaining deposit is refunded through PayPal or returned in cash. Staff or customers may cancel a booking before pickup, which refunds captured PayPal payments; refunds that PayPal has not completed remain visible for staff to retry. Any remaining fees above the deposit can be paid in cash or online with PayPal. PayPal uses server-side order creation, capture, and refunds against the sandbox API; payment amounts and ownership are checked on the server.

Staff may confirm a product return before its scheduled return date. Early return does not change the reserved rental period or reduce the agreed rental payment; late-return charges are based on returns after the scheduled date.

Each product has an admin-configured refundable security deposit and a random unique QR tag code. Staff can view inventory but cannot change products. Administrators can add, edit, and delete products, create, edit, and delete staff accounts, and delete customer accounts from the Existing accounts list. Deleting a customer also deletes the rental and payment records linked to that account. Administrators can also review checked-out/paid/completed rentals and charge records; they do not manage reservations. Products with reservation history cannot be deleted. The login and customer registration pages use MySQL/MariaDB through PDO. Customers choose a unique username during registration and can sign in with either that username or their email address.

## Database setup

Import `database/bridalrental.sql` in phpMyAdmin, or from the project directory in PowerShell run:

```powershell
Get-Content database\bridalrental.sql | C:\xampp\mysql\bin\mysql.exe -u root
```

Use `-p` if your MySQL root account has a password. The default connection uses host `127.0.0.1`, database `rental_system`, user `root`, and an empty password. Override these values with `RENTAL_DB_HOST`, `RENTAL_DB_NAME`, `RENTAL_DB_USER`, and `RENTAL_DB_PASSWORD` environment variables when needed.

`database/bridalrental.sql` is the single database setup and migration file for new and existing installations. Re-import it after updating the project to add reservation workflow, return inspection, and payment transaction records. It creates the current schema and applies the historical staff, reservation, deposit, damage-record, product-image, and retired-field changes. It also migrates an existing `users` table by assigning usernames derived from email addresses. To choose the administrator's username when creating a new admin account, set `ADMIN_USERNAME` (defaults to `admin`).

## Email verification and notifications

Install PHPMailer from the project directory if Composer dependencies have not already been installed:

```powershell
composer install
```

The application uses PHPMailer through SMTP. For Gmail, first enable 2-Step Verification on the Google account and create an App Password in Google Account security settings. Use that 16-character App Password below, not the account's normal password. Configure Apache's environment, then restart Apache. XAMPP includes `C:\xampp\apache\conf\extra\httpd-xampp.conf` from `httpd.conf`; add directives like these and replace the email and app-password placeholders:

```apache
SetEnv MAIL_HOST "smtp.gmail.com"
SetEnv MAIL_PORT "587"
SetEnv MAIL_USERNAME "your-gmail-address@gmail.com"
SetEnv MAIL_PASSWORD "your-16-character-google-app-password"
SetEnv MAIL_FROM_ADDRESS "your-gmail-address@gmail.com"
SetEnv MAIL_FROM_NAME "Angels Bridal Rental Shop"
SetEnv RENTAL_APP_URL "http://localhost/RentalSystem"
```

Keep the real App Password private: do not commit it or share it in chat. Restart Apache after updating its configuration. The application URL shown is for local development; use the public HTTPS URL when deploying online.

- `MAIL_HOST`
- `MAIL_PORT` (587 with STARTTLS)
- `MAIL_USERNAME`
- `MAIL_PASSWORD`
- `MAIL_FROM_ADDRESS`
- `MAIL_FROM_NAME`
- `RENTAL_APP_URL` (the browser-accessible application URL, without a trailing slash)
- `RENTAL_ADMIN_EMAIL` (optional; database admin accounts with email addresses are also notified)

Use an SMTP app password or provider-issued credential, not a personal account password. Keep mail credentials out of the repository.

Re-import `database/bridalrental.sql` to add `users.email_verified_at` and the email verification and password reset token tables. New customer registrations receive a one-time verification link that expires after 24 hours; the link must be confirmed on the site before that customer can sign in. Existing customer accounts without a verification timestamp must verify once using the resend form at `auth/verify.php`. Admin and staff sign-in is not gated by customer email verification.

Customers can request a password reset from the sign-in page. Reset links are single-use, expire after one hour, and resend requests are limited to one every two minutes. The response does not disclose whether an account exists. Customer and admin/staff emails are sent for booking requests; customers are emailed when a reservation is accepted, cancelled, expired, picked up, returned, charged, or paid. Refund confirmation or failure also generates customer notifications, and a refund failure alerts staff and admins. Email delivery failures are written to the PHP error log and do not reverse an already-committed rental or payment operation.

Signed-in customers see a notification bell on the storefront and account pages; staff and admins see one on the storefront and their account pages. It checks relevant reservation and payment/refund updates every 30 seconds, keeps the latest 10 updates from the past 30 days, and marks them read when the menu is opened. Re-import `database/bridalrental.sql` to add the notification read marker and update-time indexes.

## PayPal sandbox setup

Create a REST app in the [PayPal Developer Dashboard](https://developer.paypal.com/dashboard/applications/sandbox), then configure its sandbox client ID and secret in the environment used by Apache/PHP:

- `PAYPAL_CLIENT_ID`
- `PAYPAL_CLIENT_SECRET`

For XAMPP, these can be set with Apache `SetEnv` directives in the Apache configuration and require an Apache restart. Keep the secret out of the repository and never use live credentials here. The integration always calls `api-m.sandbox.paypal.com`, uses PHP (₱) as the transaction currency, and requires PHP's cURL extension. Create customer and merchant sandbox accounts in the PayPal dashboard to test checkout. Without both credentials, checkout reports that PayPal is unavailable; no payment is collected and cash flows remain available.

If PayPal confirms a capture after the booking hold can no longer be accepted, the capture is recorded and the system starts a full refund instead of discarding the payment result.

For example, add the following directives with your own sandbox app values to Apache's configuration:

```apache
SetEnv PAYPAL_CLIENT_ID "your-sandbox-client-id"
SetEnv PAYPAL_CLIENT_SECRET "your-sandbox-client-secret"
SetEnv RENTAL_APP_URL "http://localhost/RentalSystem"
```

Set `RENTAL_APP_URL` to the application’s browser-accessible base URL (without a trailing slash); PayPal uses it to return the customer to the payment receipt. When the app is accessed through an HTTPS reverse proxy such as ngrok while this setting still points to localhost, checkout uses the public request host and forwarded HTTPS scheme for PayPal’s return and cancel URLs. Set `RENTAL_APP_URL` to the current public URL if you also want verification and password-reset email links to use the ngrok address. Use your LAN site URL rather than `localhost` when customers open the site from another device.

Use the shared footer from `includes/footer.php` on new PHP pages so its copyright text and styling remain consistent across the storefront and staff, customer, and admin pages.

Authenticated admin, staff, and customer pages use `includes/account_shell_start.php` and `includes/account_shell_end.php` for a storefront-matched header, role-specific left navigation, and shared footer. On small screens, the account sidebar becomes a collapsible icon rail, while the header dropdown remains dedicated to Home, Collections, and FAQs. Account sign-out is available from the profile dropdown.

Staff tools are in the `staff` directory: view products at `staff/inventory.php`, manage reservations at `staff/reservations.php`, and print or scan QR tags through `staff/product_tag.php` and `staff/product_view.php`. Administrators can create staff accounts and review existing customer and staff account details from Manage accounts; passwords are not displayed. To build the local QR renderer after updating JavaScript dependencies, run `npm install` and `npm run build:qr` from the `javascript` directory. QR tags use the public application base URL so phones outside the local network can scan them. The current default is `https://fanfare-wow-harmonics.ngrok-free.dev/RentalSystem`; a public `RENTAL_APP_URL` overrides it, while a localhost value is ignored for QR links. Update the public URL whenever ngrok assigns a new tunnel URL. Reprint tags after changing the URL; already printed QR codes do not update.

## Sass

The Sass rules are kept in `sass\style.scss`, with shared theme/breakpoint variables, reusable mixins, and responsive rules in `sass\_variables.scss`, `sass\_mixins.scss`, and `sass\_responsive.scss`. Treat `css\style.css` as generated output: make stylesheet changes in the Sass files, then compile the entrypoint to the top-level `css` folder. Run this once to generate the CSS:

```powershell
sass.cmd --no-source-map sass\style.scss css\style.css
```

To recompile automatically while editing Sass files, start watch mode from the project directory:

```powershell
sass.cmd --watch --no-source-map sass\style.scss css\style.css
```

## Create the admin account

After importing the schema and starting MySQL, run this once in PowerShell:

```powershell
$env:ADMIN_EMAIL = "admin@example.com"
$env:ADMIN_USERNAME = "admin"
C:\xampp\php\php.exe database\create_admin.php
Remove-Item Env:ADMIN_EMAIL
Remove-Item Env:ADMIN_USERNAME
```

The script prints a randomly generated temporary password once. Save it securely; the database stores only its password hash. If `ADMIN_EMAIL` is omitted, the account email is `admin@rentalsystem.local`. Running the script again for the same email does not overwrite the existing account.

Open `http://localhost/RentalSystem/` to reach the login page. Customers can register through the link on that page.
