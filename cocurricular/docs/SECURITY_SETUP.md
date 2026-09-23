# Co-Curricular Module — Security & Environment Setup Guide

This document describes the security practices, environment variable configuration, and credential handling for the **Co-Curricular Module** of the Student Management System (SMS2).

---

## 1. Overview of Environment Variables

All sensitive credentials and environment-specific settings are decoupled from source code and managed via environment variables.

| Variable Name | Required | Default / Example | Purpose / Description |
| :--- | :---: | :--- | :--- |
| `OPENAI_API_KEY` | Optional* | `YOUR_OPENAI_API_KEY_HERE` | Server-side OpenAI API key used by `cocurricular-ai.php` to draft event and club announcements. |
| `COCURRICULAR_OPENAI_MODEL` | Optional | `gpt-4.1` | OpenAI model identifier (e.g. `gpt-4.1`, `gpt-4o-mini`). |
| `FIREBASE_API_KEY` | Optional* | `YOUR_FIREBASE_API_KEY_HERE` | Firebase Web API key used for in-browser push messaging & token registration. |
| `FIREBASE_AUTH_DOMAIN` | Optional* | `project-id.firebaseapp.com` | Firebase authentication domain for Web Push SDK. |
| `FIREBASE_PROJECT_ID` | Optional* | `project-id` | Firebase project identifier for both FCM HTTP v1 dispatch and client SDK. |
| `FIREBASE_STORAGE_BUCKET` | Optional | `project-id.firebasestorage.app` | Firebase storage bucket. |
| `FIREBASE_MESSAGING_SENDER_ID` | Optional* | `123456789012` | Firebase Cloud Messaging Sender ID. |
| `FIREBASE_APP_ID` | Optional* | `1:123456789012:web:abcdef` | Firebase Web Application ID. |
| `FIREBASE_VAPID_KEY` | Optional* | `YOUR_VAPID_KEY_HERE` | Public Web Push Certificate (VAPID key) required by Web Push browsers. |
| `FIREBASE_SERVICE_ACCOUNT_PATH`| Optional* | `config/firebase-service-account.json` | Relative or absolute path to the Firebase Service Account JSON key file. |
| `FIREBASE_CLIENT_EMAIL` | Optional | `firebase-adminsdk@...` | Alternative to file: Service account client email. |
| `FIREBASE_PRIVATE_KEY` | Optional | `-----BEGIN PRIVATE KEY...` | Alternative to file: Service account private key string. |
| `DB_HOST` | Required | `localhost` | MySQL host. |
| `DB_PORT` | Required | `3306` | MySQL port. |
| `DB_NAME` | Required | `cocurricular_db` | Co-Curricular database name. |
| `DB_USER` | Required | `root` | Database username. |
| `DB_PASS` | Optional | *(empty)* | Database password. |

*\* Required only if utilizing the AI announcement generator or Firebase Cloud Messaging features.*

---

## 2. Where to Retrieve Credentials

### A. OpenAI API Key
1. Go to the [OpenAI Platform Dashboard](https://platform.openai.com/api-keys).
2. Log in and select **API Keys** from the navigation menu.
3. Click **Create new secret key**, give it a name (e.g., `sms2-cocurricular`), and copy the key immediately.

### B. Firebase Web Client SDK Configuration
1. Open the [Firebase Console](https://console.firebase.google.com/).
2. Select your project (e.g., `co-curricular-management`).
3. Click the **Gear icon (Project settings)** > **General**.
4. Scroll down to the **Your apps** section and select your Web app (`</>`).
5. Copy the configuration object values (`apiKey`, `authDomain`, `projectId`, `storageBucket`, `messagingSenderId`, `appId`).

### C. Firebase VAPID Public Key
1. In Firebase Console, go to **Project settings** > **Cloud Messaging**.
2. Scroll to the **Web configuration** card under **Web Push certificates**.
3. If no key pair exists, click **Generate key pair**.
4. Copy the public key string.

### D. Firebase Service Account Private Key (Server-Side FCM Dispatcher)
1. In Firebase Console, go to **Project settings** > **Service accounts**.
2. Ensure **Node.js / PHP / General** is selected and click **Generate new private key**.
3. Download the JSON file.
4. Save this file locally to:
   ```text
   modules/cocurricular/config/firebase-service-account.json
   ```
   *(Note: This file is ignored by `.gitignore` and must never be pushed to Git).*

---

## 3. Local Setup Instructions

### Step 1: Create your local `.env` file
Copy the `.env.example` template:
```bash
# Windows PowerShell
Copy-Item modules/cocurricular/.env.example modules/cocurricular/.env

# Linux / macOS
cp modules/cocurricular/.env.example modules/cocurricular/.env
```

### Step 2: Fill in your credentials
Open `modules/cocurricular/.env` in your editor and supply your real keys.

### Step 3: Run the project
Ensure Apache and MySQL are running in XAMPP.
Open your browser and navigate to:
```text
http://localhost/sms2-capstone/modules/cocurricular/
```
The application will automatically detect and load your `.env` configuration.

---

## 4. Git Security & Safe Workflow

### Files That Must NEVER Be Committed to Git:
The following files contain private credentials and are ignored by `.gitignore`:
- `.env` and all `.env.*` (except `.env.example`)
- `firebase-service-account.json` (except `firebase-service-account.example.json`)
- `config/local.php`
- `storage/keys/*`
- Any file containing raw private keys, passwords, or tokens

### Pre-Commit Checklist:
Before running `git commit` or `git push`:
1. Check `git status`:
   ```bash
   git status
   ```
   Verify that no `.env` or `.json` credential files appear under "Changes to be committed".
2. Test for accidental secrets in staged files:
   ```bash
   git diff --staged
   ```
3. Never use `git add .` or `git add -A` blindly. Stage specific files or folders (e.g., `git add cocurricular/pages/`).

---

## 5. Security & Credential Rotation Guidelines

If any secret or key is ever accidentally exposed in Git history or pushed to a public repository:
1. **Rotate immediately**: Do not rely on simply deleting the file in a new commit. The secret remains visible in Git commit history.
2. **OpenAI**: Navigate to OpenAI API Keys and revoke the compromised key. Generate a replacement.
3. **Firebase Service Account**: In Google Cloud Console (IAM & Admin > Service Accounts), delete the compromised key ID and create a fresh JSON key.
4. **Firebase Web API Key**: In Google Cloud Console (APIs & Services > Credentials), apply API restrictions (limit to FCM and Firebase Installations) and HTTP referrer restrictions to allow only authorized domains.
