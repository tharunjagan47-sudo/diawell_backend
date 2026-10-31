path = r"c:\Users\SCAD LAB\AndroidStudioProjects\diawell123\app\src\main\java\com\simats\diawell123\ui\home\HomeScreen.kt"
with open(path, "r", encoding="utf-8") as f:
    content = f.read()

target = """                                    Column {
                                        Text(
                                            text = data?.greeting?.takeIf { it.isNotBlank() } ?: "Good morning, ${ProfileState.userName} \uD83D\uDC4B",
                                            fontSize = 20.sp,
                                            fontWeight = FontWeight.Bold,
                                            color = Color.White,
                                        )
                                        Text(
                                            text = data?.wellnessSubtitle?.takeIf { it.isNotBlank() } ?: "Daily Wellness \u2022 94% On Track",
                                            fontSize = 12.sp,
                                            color = Color.White.copy(alpha = 0.85f),
                                        )
                                    }

                                    // Top Right Actions: Bell & Profile Avatar
                                    Row(
                                        verticalAlignment = Alignment.CenterVertically,
                                        horizontalArrangement = Arrangement.spacedBy(8.dp),
                                    ) {
                                        // Quick Theme Toggle Button (\u2600\uFE0F / \uD83C\uDF19)
                                        Box(
                                            modifier = Modifier
                                                .clip(RoundedCornerShape(21.dp))
                                                .background(Color.White.copy(alpha = 0.25f))
                                                .clickable { ThemeManager.toggleTheme() }
                                                .padding(horizontal = 10.dp, vertical = 8.dp),
                                            contentAlignment = Alignment.Center,
                                        ) {
                                            Text(
                                                text = if (AppTheme.colors.isDark) "\uD83C\uDF19" else "\u2600\uFE0F",
                                                fontSize = 16.sp,
                                            )
                                        }

                                        Box(
                                            modifier = Modifier
                                                .clip(RoundedCornerShape(21.dp))
                                                .background(Color.White.copy(alpha = 0.25f))
                                                .clickable { onNotificationsClick() }
                                                .padding(horizontal = 10.dp, vertical = 8.dp),
                                            contentAlignment = Alignment.Center,
                                        ) {
                                            Text(text = "\uD83D\uDD14", fontSize = 16.sp)
                                        }

                                        Box(
                                            modifier = Modifier.clickable { onProfileClick() },
                                        ) {
                                            ProfileAvatarView(
                                                sizeDp = 42.dp,
                                                fontSizeSp = 15,
                                                bgCircleColor = Color.White.copy(alpha = 0.25f),
                                                textColor = Color.White,
                                            )
                                        }
                                    }"""

replacement = """                                    Column(
                                        modifier = Modifier
                                            .weight(1f)
                                            .padding(end = 10.dp),
                                    ) {
                                        Text(
                                            text = data?.greeting?.takeIf { it.isNotBlank() } ?: "Good morning, ${ProfileState.userName} \uD83D\uDC4B",
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
                                        // Quick Theme Toggle Button (\u2600\uFE0F / \uD83C\uDF19) - Perfectly Circular
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
                                                text = if (AppTheme.colors.isDark) "\uD83C\uDF19" else "\u2600\uFE0F",
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
                                                .clickable { onNotificationsClick() },
                                            contentAlignment = Alignment.Center,
                                        ) {
                                            Text(text = "\uD83D\uDD14", fontSize = 15.sp)
                                        }

                                        // Profile Avatar - Crisp, distinct, perfectly circular with specular glass ring
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
                                    }"""

content_norm = content.replace("\r\n", "\n")
target_norm = target.replace("\r\n", "\n")
replacement_norm = replacement.replace("\r\n", "\n")

if target_norm in content_norm:
    new_content = content_norm.replace(target_norm, replacement_norm)
    with open(path, "w", encoding="utf-8") as f:
        f.write(new_content)
    print("Successfully updated HomeScreen.kt top-right profile header!")
else:
    print("Target not found in HomeScreen.kt")

