# DiaWell Complete Backend APIs & Database

Comprehensive PHP REST APIs and MySQL Database Schema for the **DiaWell** diabetes wellness management application, aligned with all Android Jetpack Compose UI screens.

---

## 📁 Complete Backend Directory Structure

```
DiaWell_backend/
├── config/
│   ├── db.php                          # PDO MySQL Database Connection
│   └── helpers.php                     # CORS, JSON response, request parsing, session & user_id resolver
├── auth/
│   ├── signup.php                      # User Registration (SignUpScreen.kt)
│   ├── login.php                       # User Login & Session (LoginScreen.kt)
│   ├── forgotpassword.php              # Password Reset (ForgotPasswordScreen.kt)
│   ├── patientdetails.php              # Patient Details - Step 1 (PatientProfileScreen.kt)
│   ├── healthpreferences.php           # Health Preferences - Step 2 (HealthPreferencesScreen.kt)
│   ├── foodpreferences.php             # Food Preferences Settings (FoodPreferencesSettingsScreen.kt)
│   ├── editprofile.php                 # Personal Profile Editing (EditProfileScreen.kt)
│   ├── uploadprofile.php               # Photo Upload & Full Profile Fetch
│   └── myprofile.php                   # Photo & Profile Alias
├── glucose/
│   ├── log.php                         # Log Glucose Reading (LogGlucoseScreen.kt)
│   ├── history.php                     # Glucose History & Chart Data (GlucoseHistoryScreen.kt)
│   └── details.php                     # Reading Details & Trend Summary (ReadingDetailsScreen.kt)
├── diet/
│   ├── log.php                         # Log Meal & Get Daily Logs (FoodLogScreen.kt)
│   ├── explore.php                     # Search & Explore Foods Catalog (ExploreFoodScreen.kt)
│   ├── details.php                     # Detailed Food Nutrition & GI Impact (FoodDetailsScreen.kt)
│   └── summary.php                     # Daily & Macro Nutrition Summary (NutritionSummaryScreen.kt)
├── meds/
│   ├── list.php                        # Scheduled Medications List (MedicationsScreen.kt)
│   ├── add.php                         # Set Medication Reminder (SetMedicationReminderScreen.kt)
│   ├── update_status.php               # Mark Taken / Missed / Upcoming (MedicationReminderScreen.kt)
│   ├── search.php                      # Search Common Medications (SearchMedicationScreen.kt)
│   └── delete.php                      # Delete Medication Reminder
├── activity/
│   ├── log.php                         # Log Workout Session (ActivityTrackingScreen.kt)
│   ├── history.php                     # Workout History & Totals (ActivityHistoryScreen.kt)
│   └── recommendations.php             # Dynamic Targets based on Glucose & Weight
├── home/
│   └── dashboard.php                   # Unified Home Dashboard Metrics (HomeScreen.kt)
├── reports/
│   └── weekly.php                      # Weekly Health Report & Adherence (WeeklyReportScreen.kt)
├── notifications/
│   ├── list.php                        # Notifications List (NotificationsScreen.kt)
│   ├── mark_read.php                   # Mark Notification as Read
│   └── clear_all.php                   # Clear All Notifications
├── ai/
│   └── chat.php                        # JARVIS AI Health Chatbot & History (AiChatbotScreen.kt)
├── settings/
│   ├── change_password.php             # Change Password (ChangePasswordScreen.kt)
│   ├── delete_account.php              # Permanent Account Deletion (DeleteAccountScreen.kt)
│   ├── export_records.php              # Export Health Data JSON/CSV (ExportRecordsScreen.kt)
│   └── notification_preferences.php    # Reminder Notification Toggles (NotificationPreferencesScreen.kt)
├── upload/
│   └── profile_photos/                 # Uploaded profile photos storage
├── diawell.sql                         # Complete MySQL database schema & seed data
├── DiaWell_Complete_API.postman_collection.json # Master Postman Collection
└── README.md
```

---

## 🗄️ Database Tables (11 Total)

1. **`users`**: Authentication credentials, demographics, diabetes type, weight, height, profile photo, and current/target glucose.
2. **`health_preferences`**: Health focus area, dietary pattern, food allergies, and exercise routine.
3. **`food_preferences`**: Preferred diet type (Vegetarian, Non-Vegetarian, Vegan), allergies, styles, and foods to avoid.
4. **`glucose_readings`**: Blood glucose entries (Fasting, After meal, etc.), timestamps, and notes.
5. **`food_items`**: Indian & diabetic-friendly meals catalog with calories, carbs, protein, fat, fiber, and GI ratings.
6. **`food_logs`**: User-logged daily meals with macros and meal categories.
7. **`medications`**: Prescriptions, dosages, schedules, meal timings, and taken/missed statuses.
8. **`activity_logs`**: Logged workouts (walking, cycling, running), duration, distance, calories burned, and pre/post glucose.
9. **`notifications`**: Medication reminders, meal check-ins, glucose prompts, and report readiness alerts.
10. **`ai_chat_messages`**: Conversational chat history with Dr. JARVIS AI assistant.
11. **`notification_preferences`**: User toggles for enabling/disabling individual reminder categories.
