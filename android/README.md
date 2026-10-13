# BMLHS Android app

Android app built with Kotlin and Jetpack Compose for teachers and authorized
school administrators. It provides a branded preloader and welcome screen,
followed by a teacher-only login. After authentication, the live website is the
app's only Admin interface. The previous native Admin dashboard and its module
screens are not shown. Server-side role and module permissions remain
authoritative; the app does not embed or expose database credentials.

After a successful teacher login, the app opens the live `/dist/index.php`
Admin Panel in its embedded browser. It exchanges the native access token for
the website's PHP session over HTTPS; the token is sent in an Authorization
header and is never placed in a URL. The website's responsive navigation,
sidebar, forms and page controls run in the embedded browser. App sessions use
the Admin Panel's dark theme. Pages are always requested from the server; when
there is no network connection, the app displays a connection message rather
than opening a native or cached substitute.

The Android launcher icon uses the complete school emblem, scaled inside both
adaptive and legacy icon safe areas so system icon masks do not crop it.

## Build

- Android Studio with JDK 17
- Android SDK Platform 35
- Open this `android` directory as the Gradle project
- In VS Code, run **Install JDK 17 for BMLHS Android build** if JDK 17 is not
  installed. It downloads the official Eclipse Temurin JDK archive from
  Adoptium and installs it under `%LOCALAPPDATA%\Programs\BMLHS\jdk-17` without
  requiring an administrator account.
- The VS Code build task uses `cmd.exe` (PowerShell is not required), detects
  `ANDROID_HOME`/`ANDROID_SDK_ROOT`, and uses a separate Gradle cache under
  `%LOCALAPPDATA%\Programs\BMLHS` to avoid lock conflicts with other Gradle
  projects. It can install the missing SDK platform/build-tools when
  `sdkmanager` is available (you may be prompted to accept Android SDK
  licenses).
- If the SDK is not found, install **Android SDK Platform 35** and **Android
  SDK Build-Tools 35.0.0** in Android Studio → **Tools → SDK Manager**. The
  default Windows SDK location is `%LOCALAPPDATA%\Android\Sdk`.
- PowerShell is not required; the build uses the Windows command processor.
- Build a test APK from the `android` directory with
  `.\build-debug.cmd` (output:
  `app/build/outputs/apk/debug/app-debug.apk`)
- In VS Code, run the **Build BMLHS debug APK** build task from the workspace.
- To force Gradle to rerun every build task, use **Rebuild BMLHS debug APK from scratch**.
- Verify the generated package with the **Verify BMLHS debug APK** task.
- To copy the built APK to `dist/downloads` and publish it over the configured
  FTPS connection, run **Deploy BMLHS debug APK**. The task verifies the
  uploaded file size and SHA-256 before reporting success.
- To publish the profile responsive and favicon fixes, run
  **Deploy BMLHS profile responsive fixes**. It stages both PHP files, verifies
  their SHA-256, and retains timestamped remote backups.
- Default API base: `https://bmlhs.site/bmlhs/api/v1/`
- Override a test server with `-PBMLHS_API_BASE_URL=https://your-host/api/v1/`
- The embedded Admin Panel URL is derived from the API host and starts at
  `https://your-host/dist/mobile-session.php`; after server-side token
  validation it redirects to `https://your-host/dist/index.php`.

The launcher shows a branded preloader and welcome screen with an Admin Panel
login option. The login screen contains only teacher sign-in controls. Public
and student modules are not exposed. Student accounts are rejected by the app.

The app uses the native API for teacher authentication, then uses the website
session for Admin Panel access. Access and refresh tokens are encrypted with
Android Keystore. Admin forms and data changes run through the website and are
sent directly to the server.

## Release APK signing

Release builds are unsigned unless signing credentials are provided through the
build environment. Keep the keystore and its passwords outside source control.
Set all four variables before running `gradle assembleRelease`:

- `BMLHS_RELEASE_STORE_FILE`: absolute path to the release keystore
- `BMLHS_RELEASE_STORE_PASSWORD`: keystore password
- `BMLHS_RELEASE_KEY_ALIAS`: signing key alias
- `BMLHS_RELEASE_KEY_PASSWORD`: signing key password

The build rejects partially configured signing credentials. Preserve secure
backups of the keystore and passwords; losing them prevents signing future
updates with the same app identity.

## Backend JSON API reference

The API endpoints below are retained for the backend and older native-module
implementations. They are not separate screens in the current app; Admin work
is performed in the embedded website.

The native Android app authenticates with the staff account type and uses
mobile access/refresh tokens. The website login page remains available for
browser users and for the legacy WebView activity.

### Authentication
- `POST auth/login.php`: JSON `{ "account_type": "student|staff", "mobile": "...", "password": "..." }`
- `POST auth/refresh.php`: rotate an unexpired refresh token and issue a new access/refresh-token pair
- `GET auth/me.php`: authenticated profile and staff permission summary
- `POST auth/logout.php`: revoke the current mobile session

### Account & Student
- `GET account/summary.php?session_year=YYYY`: own student profile, attendance and published results, or staff role/assigned classes
- `GET|POST admin/students.php`: student list/detail/edit/create/password reset, direct admission options and roll availability check, pending online application review/approval/rejection/restoration, promotion candidates/options, and validated class/SSC promotion (module-gated: `students`, `promotions`)

### Teacher Features
- `GET|POST teacher/attendance.php`: assigned-class roster and token-authorized attendance reads/writes
- `GET|POST teacher/marks.php`: assigned-subject marks, drafts and final submission

### Academic Structure (Admin)
All academic structure endpoints are module-gated. Staff must have the corresponding module permission in `account/summary.php` permissions array.
- `GET|POST admin/classes.php`: class list/detail/create/edit/delete/toggle-active (module: `classes`)
- `GET|POST admin/sections.php`: section list/detail/create/edit/delete/toggle-active by class (module: `sections`)
- `GET|POST admin/subjects.php`: subject list/detail/create/edit/delete/toggle-active, copy subjects between classes (module: `subjects`)
- `GET|POST admin/exams.php`: exam list/detail/create/edit/delete/toggle-active (module: `exams`)
- `GET|POST admin/periods.php`: period (time slot) list/detail/create/edit/delete (module: `periods`)

### Routine & Results (Admin)
All routine and results endpoints require module-gated authorization. Staff must have the corresponding module permission.
- `GET|POST admin/routine.php`: class routine read/edit/save/clear/copy; query params: `class_id`, `section_id` (module: `routine`)
- `GET|POST admin/exam-routine.php`: exam routine read/edit/save/clear/copy; query params: `exam_id`, `class_id`, `section_id` (module: `exam-routine`)
- `GET|POST admin/results.php`: result list (paginated, filterable by exam/session), detail view (module: `results`)
- `POST admin/results.php?action=toggle_publication`: publish/hide results by exam/session (module: `result-publication`)

### CMS Management (Admin)
- `GET|POST admin/notices.php`: notice management (module: `notices`)
- `GET|POST admin/calendar.php`: academic calendar CRUD (module: `events`)
- `GET|POST admin/headlines.php`: public ticker headlines (module: `headlines`)
- `GET|POST admin/events.php`: event list/create/edit/publish/hide/delete; create 1280×720 cover and up to 8 cropped gallery images per save, and delete existing event photos (module: `events`)
- `GET|POST admin/gallery.php`: create albums with optional 1200×900 cover, add 1200×900 photos to optional albums, publish/hide or delete albums/photos (module: `cms-gallery`)
- `GET|POST admin/downloads.php`: list, upload allowed document/image files up to 5 MB, publish/hide or delete downloads (module: `downloads`)

### Public Endpoints
- `GET public/home.php`: school profile and latest notices
- `GET public/catalog.php`: active classes and published exams
- `GET public/calendar.php?year=YYYY&month=M`: events and academic calendar
- `GET public/staff.php`: active public staff directory
- `GET public/downloads.php`: currently published school downloads
- `GET public/gallery.php?album_id=ID`: published gallery albums/photos
- `GET public/routine.php?class_id=ID&section_id=ID`: published class routine
- `GET public/results.php?class_id=ID&exam_id=ID&session_year=YYYY`: published class results

Mobile access keys and rotating refresh keys are random, expire after 30 days, and
only their SHA-256 hashes are stored by the PHP service. Refresh keys are
single-use and are rotated after a successful refresh. Android stores both
encrypted using Android Keystore and renews the session when account validation
finds an expired access key. Public notices, calendars, staff, routines and
published results are cached locally; private cached rows are cleared on logout.
Admin write requests that fail at the network layer are stored encrypted with
Android Keystore in a staff-account-scoped queue. They are never replayed
automatically: staff must confirm before sending queued writes. The API uses
per-account idempotency keys to safely return a completed response on retry;
uncertain or rejected requests remain visible for review or discard. A retry
with a new key requires a separate explicit confirmation and may create a
duplicate if the earlier write already completed.
Updated app builds send idempotency keys with Admin `POST` requests; the API
continues to accept requests from older app builds that do not send the header.

The legacy mobile API supports student login by reusing `students_info`
mobile/password credentials. This login is not exposed by the teacher-only
Android app.

## Legacy native module reference

These legacy native-module capabilities are not exposed in the current app UI.
Use the embedded Admin Panel for school administration.

### Public API reference
- ✅ School information, notices, and latest updates
- ✅ Academic calendar with events
- ✅ Staff directory
- ✅ Published class routines
- ✅ Published exam results with academic session filtering
- ✅ Download library
- ✅ Gallery albums and photos
- ✅ Local caching of calendar, notices, routines, and results

### Student API reference (not exposed in the app UI)
- ✅ Student profile and personal information (read-only)
- ✅ Attendance history and percentage tracking
- ✅ Published exam results and marks viewing
- ✅ Personal session year selection and filtering
- ✅ Cached local data cleared on logout for privacy

### Teacher Features (Authenticated)
- ✅ Assigned class attendance roster with date selection
- ✅ Token-authorized attendance entry and submission
- ✅ Subject marks entry for assigned classes
- ✅ Marks draft persistence and final submission workflow
- ✅ Edit-lock enforcement after final submission (Admin unlock required)

### Admin Features (Module-Gated Authorization)
All admin operations require explicit module permissions returned by `account/summary.php`. Staff accounts only see admin actions for modules they are authorized to manage.

#### Academic Structure Management
- ✅ **Classes**: list, create, edit, delete, toggle active status
- ✅ **Sections**: list, create, edit, delete, toggle active status (scoped by class)
- ✅ **Subjects**: list, create, edit, delete, toggle active status, copy subjects between classes
- ✅ **Exams**: list, create, edit, delete, toggle active status
- ✅ **Periods**: list, create, edit, delete (time slots for routine)

#### Routine & Results Management
- ✅ **Class Routine**: read, edit, save, clear with confirmation, copy routine between classes/sections
- ✅ **Exam Routine**: read, edit, save, clear with confirmation, copy routine between exam/class/section combinations
- ✅ **Results**: paginated list (filterable by exam, session year), detail viewing, no edit (view-only)
- ✅ **Results Publication**: publish/hide results by exam and session year with confirmation

#### CMS & Content Management
- ✅ **Notices**: create, edit, delete (text-based, no file attachments yet)
- ✅ **Calendar Events**: create, edit, delete, publish/hide with date validation
- ✅ **Headlines**: manage public ticker headlines
- ✅ **Website Events**: create/edit with optional 1280×720 cover and up to 8 gallery photos, publish/hide, delete, and delete existing event photos — module `events`
- ✅ **Gallery**: create albums with optional cover and add cropped photos to optional albums; publish/hide or delete albums/photos — module `cms-gallery`
- ✅ **Downloads**: upload PDF/Word/Excel/JPG/PNG files up to 5 MB, publish/hide, and delete — module `downloads`

#### Student Administration
- ✅ **Student List**: paginated, searchable by name/roll/mobile, filterable by status/session/class/section
- ✅ **Student Detail**: view full student record
- ✅ **Student Edit**: edit safe fields (name, gender, religion, address, phone, etc.) — restricted; class/section/roll/status fields cannot be changed via this endpoint
- ✅ **Direct Student Admission**: create an admitted student with an active class/section, session, available roll, required names, mobile and an initial password; roll availability is checked before submit and again transactionally by the API; optional birth registration, address and previous-school details are supported, and credentials are stored as a password hash
- ✅ **Student Password Reset**: set a verified new password from the student detail screen; old mobile-app sessions are revoked
- ✅ **Online Admission Review**: inspect pending applications, verify applicant details, choose an active section/session/roll and approve; only applications with a consumed admission token can be approved, and occupied rolls are rejected
- ✅ **Online Admission Rejection**: reject a pending application without deleting its record; restore rejected online applications from the Status 2 student list, with the server rechecking the consumed admission token
- ✅ **Promotion**: validated promotion to the next academic class, move to an SSC candidate batch, or return an SSC candidate to Class 10; API supplies eligible destinations and active sections, while the server revalidates session, roll availability, student status and class sequence at submission

### Authorization & Security
- Module permissions checked before each admin action (classes, sections, subjects, exams, periods, students, promotions, routine, exam-routine, results, result-publication, events, downloads, cms-gallery, notices, headlines)
- Wildcard permission `*` (head admin) automatically expanded to all available modules
- Bearer-token authentication for all endpoints; tokens expire after 30 days
- Attendance writes scoped to staff's authorized classes and sections
- Marks writes scoped to staff's assigned subjects; locked after final submission
- Admin write operations require explicit authorization header

### Limitations & Deferred Work
- Student deletion is intentionally unavailable to protect enrollment and historical records
- Admin writes can be queued offline and explicitly synced; uncertain/conflicting queue entries require staff review, and edits/merges are not automatic
- CMS image crop-position preview is available for event covers/gallery photos and Gallery album covers/photos
