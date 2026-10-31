path = r"c:\Users\SCAD LAB\AndroidStudioProjects\diawell123\app\src\main\java\com\simats\diawell123\ui\home\HomeScreen.kt"
with open(path, "r", encoding="utf-8", errors="surrogateescape") as f:
    lines = f.readlines()

new_block = """                                    Column(
                                        modifier = Modifier
                                            .weight(1f)
                                            .padding(end = 10.dp),
                                    ) {
                                        Text(
                                            text = data?.greeting?.takeIf { it.isNotBlank() } ?: "Good morning, ${ProfileState.userName} 👋",
                                            fontSize = 19.sp,
                                            fontWeight = FontWeight.Bold,
                                            color = Color.White,
                                            maxLines = 1,
                                            overflow = TextOverflow.Ellipsis,
                                        )
                                        Text(
                                            text = data?.wellnessSubtitle?.takeIf { it.isNotBlank() } ?: "Daily Wellness • 94% On Track",
                                            fontSize = 12.sp,
                                            color = Color.White.copy(alpha = 0.85f),
                                            maxLines = 1,
                                            overflow = TextOverflow.Ellipsis,
                                        )
                                    }

                                    // Top Right Actions: Theme Toggle, Notifications Bell & Profile Avatar
                                    Row(
                                        verticalAlignment = Alignment.CenterVertically,
                                        horizontalArrangement = Arrangement.spacedBy(8.dp),
                                    ) {
                                        // Quick Theme Toggle Button (☀️ / 🌙) - Perfectly Circular
                                        Box(
                                            modifier = Modifier
                                                .size(36.dp)
                                                .clip(CircleShape)
                                                .background(Color.White.copy(alpha = 0.20f))
                                                .border(1.dp, Color.White.copy(alpha = 0.35f), CircleShape)
                                                .clickable { ThemeManager.toggleTheme() },
                                            contentAlignment = Alignment.Center,
                                        ) {
                                            Text(
                                                text = if (AppTheme.colors.isDark) "🌙" else "☀️",
                                                fontSize = 15.sp,
                                            )
                                        }

                                        // Notification Bell - Perfectly Circular
                                        Box(
                                            modifier = Modifier
                                                .size(36.dp)
                                                .clip(CircleShape)
                                                .background(Color.White.copy(alpha = 0.20f))
                                                .border(1.dp, Color.White.copy(alpha = 0.35f), CircleShape)
                                                .clickable { onNotificationsClick() }
                                                .padding(2.dp),
                                            contentAlignment = Alignment.Center,
                                        ) {
                                            Text(text = "🔔", fontSize = 15.sp)
                                        }

                                        // Profile Avatar - Crisp, distinct, perfectly circular with specular border
                                        Box(
                                            modifier = Modifier
                                                .size(42.dp)
                                                .clip(CircleShape)
                                                .clickable { onProfileClick() },
                                            contentAlignment = Alignment.Center,
                                        ) {
                                            ProfileAvatarView(
                                                sizeDp = 42.dp,
                                                fontSizeSp = 15,
                                                bgCircleColor = Color.White.copy(alpha = 0.25f),
                                                borderColor = Color.White.copy(alpha = 0.90f),
                                                textColor = Color.White,
                                            )
                                        }
                                    }
"""

lines[200:256] = [new_block]

with open(path, "w", encoding="utf-8", errors="surrogateescape") as f:
    f.writelines(lines)

print("Successfully replaced header block in HomeScreen.kt")
