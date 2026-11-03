import pandas as pd

csv_path = r'C:\xampp\htdocs\DiaWell_backend\data\Indian_Food_Nutrition_Processed.csv'
df = pd.read_csv(csv_path)

terms = ['Dal dhokli', 'Semolina dhokla', 'Dahi vada']
for t in terms:
    matches = df[df['Dish Name'].str.contains(t, case=False, na=False)]
    print(f"=== Search: '{t}' ({len(matches)} matches) ===")
    for idx, row in matches.iterrows():
        dish = row['Dish Name']
        print(f"Row {idx+2} (CSV Line {idx+2}): Dish='{dish}'")
        for col in ['Calories (kcal)', 'Carbohydrates (g)', 'Protein (g)', 'Fats (g)', 'Free Sugar (g)', 'Fibre (g)', 'Sodium (mg)']:
            print(f"  {col}: {row[col]}")
        print()

