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

# Files to process
skip_files = {"Color.kt", "Theme.kt", "ThemeManager.kt"}

processed_count = 0

for root, dirs, files in os.walk(base_dir):
    for fname in files:
        if not fname.endswith(".kt") or fname in skip_files:
            continue
        
        path = os.path.join(root, fname)
        with open(path, "r", encoding="utf-8") as f:
            original = f.read()

        content = original

        # Check if we need to replace any of the target patterns
        has_patterns = any(pat in content for pat in [
            "0xFFFDEEF7", "0xFFF8F7FA", "0xFFF3E2F0", "0xFFF3D5E9", "0xFF811978"
        ])

        if not has_patterns:
            continue

        content = ensure_apptheme_import(content)

        # 1. Replace unselected chip / background Color(0xFFF8F7FA)
        content = content.replace("Color(0xFFF8F7FA)", "AppTheme.colors.secondaryBackground")

        # 2. Replace border Color(0xFFF3E2F0) and Color(0xFFF3D5E9)
        content = content.replace("Color(0xFFF3E2F0)", "AppTheme.colors.border")
        content = content.replace("Color(0xFFF3D5E9)", "AppTheme.colors.border")

        # 3. Replace text Color(0xFF811978)
        content = re.sub(
            r'color\s*=\s*Color\(0xFF811978\)',
            'color = if (AppTheme.colors.isDark) Color(0xFFF472B6) else Color(0xFF811978)',
            content
        )

        # 4. Replace background Color(0xFFFDEEF7) in .background(...)
        # For .background(Color(0xFFFDEEF7)) -> .background(if (AppTheme.colors.isDark) AppTheme.colors.elevatedSurface else Color(0xFFFDEEF7))
        content = content.replace(
            ".background(Color(0xFFFDEEF7))",
            ".background(if (AppTheme.colors.isDark) AppTheme.colors.elevatedSurface else Color(0xFFFDEEF7))"
        )
        content = content.replace(
            ".background(Color(0xFFFDEEF7),",
            ".background(if (AppTheme.colors.isDark) AppTheme.colors.elevatedSurface else Color(0xFFFDEEF7),"
        )
        content = content.replace(
            "if (isSelected) Color(0xFFFDEEF7) else",
            "if (isSelected) (if (AppTheme.colors.isDark) Color(0xFF831843).copy(alpha = 0.4f) else Color(0xFFFDEEF7)) else"
        )

        # 5. Replace trackColor = Color(0xFFF3E2F0) or trackColor = Color(0xFFFDEEF7)
        content = content.replace(
            "trackColor = Color(0xFFF3E2F0)",
            "trackColor = if (AppTheme.colors.isDark) AppTheme.colors.border else Color(0xFFF3E2F0)"
        )

        if content != original:
            with open(path, "w", encoding="utf-8") as f:
                f.write(content)
            processed_count += 1
            print(f"Refined: {fname}")

print(f"Batch refinement completed. Total files modified: {processed_count}")

