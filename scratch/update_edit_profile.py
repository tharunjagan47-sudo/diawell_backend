import re

path = r"c:\Users\SCAD LAB\AndroidStudioProjects\diawell123\app\src\main\java\com\simats\diawell123\ui\profile\EditProfileScreen.kt"
with open(path, "r", encoding="utf-8") as f:
    content = f.read()

# Add SessionManager import if not present
if "import com.simats.diawell123.data.local.SessionManager" not in content:
    content = content.replace("package com.simats.diawell123.ui.profile", "package com.simats.diawell123.ui.profile\nimport com.simats.diawell123.data.local.SessionManager", 1)

old_code = """                ProfileState.profileImageBitmap = bitmap.asImageBitmap()

                // Upload to server
                val tempFile = uriToFile(context, uri)
                if (tempFile != null) {
                    viewModel.uploadProfilePhoto(tempFile)
                }"""

new_code = """                val sessionManager = try {
                    SessionManager.getInstance()
                } catch (_: Exception) {
                    SessionManager.init(context)
                }
                ProfileState.saveProfileBitmap(context, bitmap, sessionManager.getUserId())

                // Upload to server
                val tempFile = uriToFile(context, uri)
                if (tempFile != null) {
                    viewModel.uploadProfilePhoto(tempFile)
                }"""

if old_code in content:
    content = content.replace(old_code, new_code)
    with open(path, "w", encoding="utf-8") as f:
        f.write(content)
    print("Updated EditProfileScreen.kt successfully!")
else:
    print("Could not find old_code in EditProfileScreen.kt")

