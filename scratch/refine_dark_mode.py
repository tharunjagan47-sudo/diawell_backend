import os
import re

base_dir = r"c:\Users\SCAD LAB\AndroidStudioProjects\diawell123\app\src\main\java\com\simats\diawell123"

def ensure_apptheme_import(content):
    if "import com.simats.diawell123.ui.theme.AppTheme" not in content:
        lines = content.splitlines(True)
        new_lines = []
        package_found = False
        for line in lines:
            new_lines.append(line)
            if not package_found and line.strip().startswith("package com.simats.diawell123"):
                new_lines.append("import com.simats.diawell123.ui.theme.AppTheme\n")
                package_found = True
        return "".join(new_lines)
    return content

# 1. Update ProfileAvatarView.kt
avatar_path = os.path.join(base_dir, "ui", "profile", "ProfileAvatarView.kt")
if os.path.exists(avatar_path):
    with open(avatar_path, "r", encoding="utf-8") as f:
        content = f.read()
    content = ensure_apptheme_import(content)
    content = content.replace(
        "bgCircleColor: Color = Color(0xFFF3E2F0),",
        "bgCircleColor: Color = if (AppTheme.colors.isDark) AppTheme.colors.elevatedSurface else Color(0xFFF3E2F0),"
    )
    content = content.replace(
        "textColor: Color = Color(0xFF811978),",
        "textColor: Color = if (AppTheme.colors.isDark) Color(0xFFF472B6) else Color(0xFF811978),"
    )
    content = content.replace(
        ".border(1.5.dp, Color.White, CircleShape)",
        ".border(1.5.dp, if (AppTheme.colors.isDark) AppTheme.colors.border else Color.White, CircleShape)"
    )
    with open(avatar_path, "w", encoding="utf-8") as f:
        f.write(content)
    print("Updated ProfileAvatarView.kt")

# 2. Update FoodDetailsScreen.kt for StyledBackButton
food_path = os.path.join(base_dir, "ui", "diet", "FoodDetailsScreen.kt")
if os.path.exists(food_path):
    with open(food_path, "r", encoding="utf-8") as f:
        content = f.read()
    old_arrow = """            val arrowPath = Path().apply {
                moveTo(w * 0.60f, h * 0.28f)
                lineTo(w * 0.38f, h * 0.50f)
                lineTo(w * 0.60f, h * 0.72f)
            }
            drawPath(
                path = arrowPath,
                color = Color(0xFF7A1C7D),"""
    new_arrow = """            val arrowPath = Path().apply {
                moveTo(w * 0.60f, h * 0.28f)
                lineTo(w * 0.38f, h * 0.50f)
                lineTo(w * 0.60f, h * 0.72f)
            }
            drawPath(
                path = arrowPath,
                color = if (AppTheme.colors.isDark) Color(0xFFF472B6) else Color(0xFF7A1C7D),"""
    if old_arrow in content:
        content = content.replace(old_arrow, new_arrow)
        with open(food_path, "w", encoding="utf-8") as f:
            f.write(content)
        print("Updated StyledBackButton in FoodDetailsScreen.kt")

# 3. Update HomeScreen.kt MetricCard
home_path = os.path.join(base_dir, "ui", "home", "HomeScreen.kt")
if os.path.exists(home_path):
    with open(home_path, "r", encoding="utf-8") as f:
        content = f.read()
    old_metric_start = """fun MetricCard(
    icon: String,
    title: String,
    value: String,
    subtitle: String,
    statusText: String,
    statusBg: Color,
    statusColor: Color,
    cardBg: Color,
    borderColor: Color,
    actionText: String,
    onActionClick: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Box(
        modifier = modifier
            .clip(RoundedCornerShape(16.dp))
            .background(cardBg)
            .border(1.dp, borderColor, RoundedCornerShape(16.dp))
            .clickable { onActionClick() }
            .padding(14.dp),
    ) {"""
    new_metric_start = """fun MetricCard(
    icon: String,
    title: String,
    value: String,
    subtitle: String,
    statusText: String,
    statusBg: Color,
    statusColor: Color,
    cardBg: Color,
    borderColor: Color,
    actionText: String,
    onActionClick: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val actualCardBg = if (AppTheme.colors.isDark) AppTheme.colors.cardBackground else cardBg
    val actualBorder = if (AppTheme.colors.isDark) AppTheme.colors.border else borderColor
    val actualStatusBg = if (AppTheme.colors.isDark) statusColor.copy(alpha = 0.22f) else statusBg
    val actualActionColor = if (AppTheme.colors.isDark) Color(0xFFF472B6) else Color(0xFFB81D85)
    Box(
        modifier = modifier
            .clip(RoundedCornerShape(16.dp))
            .background(actualCardBg)
            .border(1.dp, actualBorder, RoundedCornerShape(16.dp))
            .clickable { onActionClick() }
            .padding(14.dp),
    ) {"""
    if old_metric_start in content:
        content = content.replace(old_metric_start, new_metric_start)
        # also update statusBg and actionColor inside MetricCard
        content = content.replace(
            ".background(statusBg)\n                    .padding(horizontal = 8.dp, vertical = 3.dp),",
            ".background(actualStatusBg)\n                    .padding(horizontal = 8.dp, vertical = 3.dp),"
        )
        content = content.replace(
            'color = Color(0xFFB81D85),\n                )\n            }\n        }\n    }',
            'color = actualActionColor,\n                )\n            }\n        }\n    }'
        )
        with open(home_path, "w", encoding="utf-8") as f:
            f.write(content)
        print("Updated MetricCard in HomeScreen.kt")

# 4. Update AiChatbotScreen.kt
ai_path = os.path.join(base_dir, "ui", "ai", "AiChatbotScreen.kt")
if os.path.exists(ai_path):
    with open(ai_path, "r", encoding="utf-8") as f:
        content = f.read()
    content = ensure_apptheme_import(content)
    # Banner
    content = content.replace(
        ".background(Color(0xFFFDEEF7))\n                        .border(1.dp, Color(0xFFF3E2F0), RoundedCornerShape(12.dp))",
        ".background(AppTheme.colors.elevatedSurface)\n                        .border(1.dp, AppTheme.colors.border, RoundedCornerShape(12.dp))"
    )
    content = content.replace(
        'color = Color(0xFF811978),\n                        lineHeight = 16.sp,',
        'color = if (AppTheme.colors.isDark) Color(0xFFF472B6) else Color(0xFF811978),\n                        lineHeight = 16.sp,'
    )
    # AI Message bubble
    content = content.replace(
        ".background(Color(0xFFF8F7FA))\n                                        .border(1.dp, AppTheme.colors.border, RoundedCornerShape(16.dp))",
        ".background(AppTheme.colors.cardElevated)\n                                        .border(1.dp, AppTheme.colors.border, RoundedCornerShape(16.dp))"
    )
    content = content.replace(
        'text = "JARVIS AI ⚡🩺",\n                                            fontSize = 11.sp,\n                                            fontWeight = FontWeight.Bold,\n                                            color = Color(0xFFB81D85),',
        'text = "JARVIS AI ⚡🩺",\n                                            fontSize = 11.sp,\n                                            fontWeight = FontWeight.Bold,\n                                            color = if (AppTheme.colors.isDark) Color(0xFFF472B6) else Color(0xFFB81D85),'
    )
    # Quick prompt chips
    content = content.replace(
        ".background(Color(0xFFFDEEF7))\n                                .border(1.dp, Color(0xFFF3E2F0), RoundedCornerShape(12.dp))",
        ".background(AppTheme.colors.elevatedSurface)\n                                .border(1.dp, AppTheme.colors.border, RoundedCornerShape(12.dp))"
    )
    content = content.replace(
        'color = Color(0xFFB81D85),\n                            )',
        'color = if (AppTheme.colors.isDark) Color(0xFFF472B6) else Color(0xFFB81D85),\n                            )'
    )
    # Text field text color
    content = content.replace(
        "focusedContainerColor = AppTheme.colors.inputBackground,",
        "focusedTextColor = AppTheme.colors.textPrimary,\n                        unfocusedTextColor = AppTheme.colors.textPrimary,\n                        focusedContainerColor = AppTheme.colors.inputBackground,"
    )
    with open(ai_path, "w", encoding="utf-8") as f:
        f.write(content)
    print("Updated AiChatbotScreen.kt")

# 5. Update WeeklyReportScreen.kt
weekly_path = os.path.join(base_dir, "ui", "reports", "WeeklyReportScreen.kt")
if os.path.exists(weekly_path):
    with open(weekly_path, "r", encoding="utf-8") as f:
        content = f.read()
    content = ensure_apptheme_import(content)
    content = content.replace(
        'trackColor = Color(0xFFFDEEF7),',
        'trackColor = if (AppTheme.colors.isDark) AppTheme.colors.border else Color(0xFFFDEEF7),'
    )
    content = content.replace(
        '.background(Color(0xFFFDEEF7))\n                        .padding(12.dp),',
        '.background(AppTheme.colors.elevatedSurface)\n                        .border(1.dp, AppTheme.colors.border, RoundedCornerShape(12.dp))\n                        .padding(12.dp),'
    )
    content = content.replace(
        'color = Color(0xFF811978),',
        'color = if (AppTheme.colors.isDark) Color(0xFFF472B6) else Color(0xFF811978),'
    )
    content = content.replace(
        '.background(Color(0xFFFDEEF7))\n                        .padding(horizontal = 14.dp, vertical = 8.dp),',
        '.background(AppTheme.colors.elevatedSurface)\n                        .border(1.dp, AppTheme.colors.border, RoundedCornerShape(12.dp))\n                        .padding(horizontal = 14.dp, vertical = 8.dp),'
    )
    content = content.replace(
        '.background(Color(0xFFF8F7FA))\n                            .clickable { onDismissRequest() },',
        '.background(AppTheme.colors.secondaryBackground)\n                            .border(1.dp, AppTheme.colors.border, RoundedCornerShape(12.dp))\n                            .clickable { onDismissRequest() },'
    )
    with open(weekly_path, "w", encoding="utf-8") as f:
        f.write(content)
    print("Updated WeeklyReportScreen.kt")

# 6. Update ActivityTrackingScreen.kt
act_path = os.path.join(base_dir, "ui", "activity", "ActivityTrackingScreen.kt")
if os.path.exists(act_path):
    with open(act_path, "r", encoding="utf-8") as f:
        content = f.read()
    content = ensure_apptheme_import(content)
    content = content.replace(
        ".background(Color(0xFFFDEEF7))\n                            .border(1.dp, Color(0xFFF3D5E9), RoundedCornerShape(12.dp))",
        ".background(AppTheme.colors.elevatedSurface)\n                            .border(1.dp, AppTheme.colors.border, RoundedCornerShape(12.dp))"
    )
    content = content.replace(
        "Brush.horizontalGradient(\n                                            colors = listOf(Color.White, Color.White),\n                                        )",
        "Brush.horizontalGradient(\n                                            colors = listOf(AppTheme.colors.cardBackground, AppTheme.colors.cardBackground),\n                                        )"
    )
    content = content.replace(
        "color = Color(0xFFFDEEF7),\n                                    startAngle = 135f,",
        "color = if (AppTheme.colors.isDark) AppTheme.colors.border else Color(0xFFFDEEF7),\n                                    startAngle = 135f,"
    )
    content = content.replace(
        ".background(Color(0xFFFDEEF7))\n                        .border(1.dp, Color(0xFFF3E2F0), RoundedCornerShape(16.dp))",
        ".background(AppTheme.colors.elevatedSurface)\n                        .border(1.dp, AppTheme.colors.border, RoundedCornerShape(16.dp))"
    )
    content = content.replace(
        "color = Color(0xFF811978),",
        "color = if (AppTheme.colors.isDark) Color(0xFFF472B6) else Color(0xFF811978),"
    )
    with open(act_path, "w", encoding="utf-8") as f:
        f.write(content)
    print("Updated ActivityTrackingScreen.kt")

# 7. Update SetMedicationReminderScreen.kt
med_path = os.path.join(base_dir, "ui", "meds", "SetMedicationReminderScreen.kt")
if os.path.exists(med_path):
    with open(med_path, "r", encoding="utf-8") as f:
        content = f.read()
    content = ensure_apptheme_import(content)
    content = content.replace(
        ".background(Color(0xFFFDEEF7))\n                    .border(1.dp, Color(0xFFF3D5E9), RoundedCornerShape(16.dp))",
        ".background(AppTheme.colors.elevatedSurface)\n                    .border(1.dp, AppTheme.colors.border, RoundedCornerShape(16.dp))"
    )
    content = content.replace(
        ".background(Color.White.copy(alpha = 0.8f))",
        ".background(if (AppTheme.colors.isDark) AppTheme.colors.cardBackground else Color.White.copy(alpha = 0.8f))"
    )
    content = content.replace(
        ".background(Color.White.copy(alpha = 0.7f))",
        ".background(if (AppTheme.colors.isDark) AppTheme.colors.cardBackground else Color.White.copy(alpha = 0.7f))"
    )
    content = content.replace(
        "color = Color(0xFF7A1C7D),",
        "color = if (AppTheme.colors.isDark) Color(0xFFF472B6) else Color(0xFF7A1C7D),"
    )
    content = content.replace(
        ".background(Color(0xFFFDEEF7))\n                            .padding(horizontal = 10.dp, vertical = 4.dp),",
        ".background(AppTheme.colors.elevatedSurface)\n                            .padding(horizontal = 10.dp, vertical = 4.dp),"
    )
    content = content.replace(
        ".background(if (isSelected) Color(0xFFFDEEF7) else Color(0xFFF8F7FA))",
        ".background(if (isSelected) (if (AppTheme.colors.isDark) Color(0xFF831843).copy(alpha = 0.4f) else Color(0xFFFDEEF7)) else AppTheme.colors.secondaryBackground)"
    )
    content = content.replace(
        "focusedContainerColor = AppTheme.colors.inputBackground,",
        "focusedTextColor = AppTheme.colors.textPrimary,\n                    unfocusedTextColor = AppTheme.colors.textPrimary,\n                    focusedContainerColor = AppTheme.colors.inputBackground,"
    )
    with open(med_path, "w", encoding="utf-8") as f:
        f.write(content)
    print("Updated SetMedicationReminderScreen.kt")

# 8. Update SplashScreen.kt
splash_path = os.path.join(base_dir, "SplashScreen.kt")
if os.path.exists(splash_path):
    with open(splash_path, "r", encoding="utf-8") as f:
        content = f.read()
    content = ensure_apptheme_import(content)
    old_canvas_circles = """            drawCircle(
                color = Color(0xFFFDEEF7),
                radius = width * 0.45f,
                center = Offset(x = width * 0.1f, y = height * 0.08f),
            )

            drawCircle(
                color = Color(0xFFE0F7FA).copy(alpha = 0.6f),
                radius = width * 0.55f,
                center = Offset(x = width * 0.85f, y = height * 0.12f),
            )

            drawCircle(
                color = Color(0xFFFDEEF7),
                radius = width * 0.48f,
                center = Offset(x = width * 0.92f, y = height * 0.85f),
                style = Stroke(width = 44.dp.toPx()),
            )"""
    new_canvas_circles = """            val ambientPink = if (AppTheme.colors.isDark) Color(0xFF831843).copy(alpha = 0.20f) else Color(0xFFFDEEF7)
            val ambientCyan = if (AppTheme.colors.isDark) Color(0xFF0E7490).copy(alpha = 0.20f) else Color(0xFFE0F7FA).copy(alpha = 0.6f)

            drawCircle(
                color = ambientPink,
                radius = width * 0.45f,
                center = Offset(x = width * 0.1f, y = height * 0.08f),
            )

            drawCircle(
                color = ambientCyan,
                radius = width * 0.55f,
                center = Offset(x = width * 0.85f, y = height * 0.12f),
            )

            drawCircle(
                color = ambientPink,
                radius = width * 0.48f,
                center = Offset(x = width * 0.92f, y = height * 0.85f),
                style = Stroke(width = 44.dp.toPx()),
            )"""
    if old_canvas_circles in content:
        content = content.replace(old_canvas_circles, new_canvas_circles)
    content = content.replace(
        'color = Color(0xFF811978),',
        'color = if (AppTheme.colors.isDark) Color(0xFFF472B6) else Color(0xFF811978),'
    )
    with open(splash_path, "w", encoding="utf-8") as f:
        f.write(content)
    print("Updated SplashScreen.kt")

# 9. Update LogoutConfirmationDialog.kt
logout_path = os.path.join(base_dir, "ui", "settings", "LogoutConfirmationDialog.kt")
if os.path.exists(logout_path):
    with open(logout_path, "r", encoding="utf-8") as f:
        content = f.read()
    content = content.replace(
        ".background(AppTheme.colors.cardBackground)\n                .padding(24.dp),",
        ".background(AppTheme.colors.cardBackground)\n                .border(1.dp, AppTheme.colors.border, RoundedCornerShape(24.dp))\n                .padding(24.dp),"
    )
    content = content.replace(
        ".background(Color(0xFFFDEEF7)),",
        ".background(if (AppTheme.colors.isDark) AppTheme.colors.elevatedSurface else Color(0xFFFDEEF7))\n                        .border(1.dp, AppTheme.colors.border, CircleShape),"
    )
    with open(logout_path, "w", encoding="utf-8") as f:
        f.write(content)
    print("Updated LogoutConfirmationDialog.kt")

print("Specific screen updates completed successfully.")

