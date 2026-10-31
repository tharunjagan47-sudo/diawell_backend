path = r"c:\Users\SCAD LAB\AndroidStudioProjects\diawell123\app\src\main\java\com\simats\diawell123\MainActivity.kt"
with open(path, "r", encoding="utf-8") as f:
    c = f.read()

# Add ProfileState import if needed
if "import com.simats.diawell123.ui.profile.ProfileState" not in c:
    c = c.replace("import com.simats.diawell123.ui.profile.ProfileViewModel", "import com.simats.diawell123.ui.profile.ProfileState\nimport com.simats.diawell123.ui.profile.ProfileViewModel")

target1 = """                val sessionManager = remember {
                    try {
                        SessionManager.getInstance()
                    } catch (_: Exception) {
                        SessionManager.init(applicationContext)
                    }
                }"""

replacement1 = """                val sessionManager = remember {
                    try {
                        SessionManager.getInstance()
                    } catch (_: Exception) {
                        SessionManager.init(applicationContext)
                    }
                }

                // Initialize ProfileState from disk cache & session so avatar and username persist across launches
                LaunchedEffect(Unit) {
                    ProfileState.init(applicationContext, sessionManager.getUserId())
                }"""

target2 = 'onLoginSuccess = { currentScreen = "home" }'
replacement2 = """onLoginSuccess = {
                                ProfileState.init(applicationContext, sessionManager.getUserId())
                                currentScreen = "home"
                            }"""

target3 = """                                sessionManager.clearSession()
                                CoroutineScope(Dispatchers.IO).launch {"""

replacement3 = """                                sessionManager.clearSession()
                                ProfileState.clear()
                                CoroutineScope(Dispatchers.IO).launch {"""

c_norm = c.replace("\r\n", "\n")
t1_norm = target1.replace("\r\n", "\n")
t2_norm = target2.replace("\r\n", "\n")
t3_norm = target3.replace("\r\n", "\n")

if t1_norm in c_norm and t2_norm in c_norm and t3_norm in c_norm:
    c_norm = c_norm.replace(t1_norm, replacement1.replace("\r\n", "\n"))
    c_norm = c_norm.replace(t2_norm, replacement2.replace("\r\n", "\n"))
    c_norm = c_norm.replace(t3_norm, replacement3.replace("\r\n", "\n"))
    with open(path, "w", encoding="utf-8") as f:
        f.write(c_norm)
    print("Updated MainActivity.kt successfully")
else:
    print("Targets not found in MainActivity.kt", t1_norm in c_norm, t2_norm in c_norm, t3_norm in c_norm)

