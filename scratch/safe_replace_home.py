path = r"c:\Users\SCAD LAB\AndroidStudioProjects\diawell123\app\src\main\java\com\simats\diawell123\ui\home\HomeScreen.kt"
with open(path, "r", encoding="utf-8") as f:
    lines = f.readlines()

print(f"Read {len(lines)} lines")
assert lines[200].strip() == "Column {", f"Line 201 mismatch: {lines[200]}"
assert lines[255].strip() == "}", f"Line 256 mismatch: {lines[255]}"
assert lines[256].strip() == "}", f"Line 257 mismatch: {lines[256]}"

replacement = """                                    Column(
                                        modifier = Modifier
                                            .weight(1f)
                                            .padding(end = 10.dp),
                                    ) {
                                        Text(
                                            text = data?.greeting?.takeIf { it.isNotBlank() } ?: "Good morning, ${ProfileState.userName} \U0001F44B",
                                            fontSize = 19.sp,
                                            fontWeight = FontWeight.Bold,
                                            color = Color.White,
                                            maxLines = 1,
                                            overflow = TextOverflow.Ellipsis,
                                        )
                                        Text(
                                            text = data?.wellnessSubtitle?.takeIf { it.isNotBlank() } ?: "Daily Wellness \u2022 94% On Track",
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
                                        // Quick Theme Toggle Button (\u2600\uFE0F / \U0001F319) - Perfectly Circular
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
                                                text = if (AppTheme.colors.isDark) "\U0001F319" else "\u2600\uFE0F",
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
                                            Text(text = "\U0001F514", fontSize = 15.sp)
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

replacement_lines = [l + "\n" for l in replacement.splitlines()]

new_lines = lines[:200] + replacement_lines + lines[256:]
print(f"New lines count: {len(new_lines)}")

with open(path, "w", encoding="utf-8") as f:
    f.writelines(new_lines)

print("Updated HomeScreen.kt successfully!")
