import pandas as pd

csv_path = r'C:\xampp\htdocs\DiaWell_backend\data\Indian_Food_Nutrition_Processed.csv'
df = pd.read_csv(csv_path)

high_fiber = df[df['Fibre (g)'] >= 3.0].copy()
print(f"Total dishes with Fibre >= 3.0g: {len(high_fiber)} out of {len(df)}")

# Show top 25 high-fiber dishes
print("\nSample high-fiber dishes:")
for idx, r in high_fiber.head(30).iterrows():
    name = r['Dish Name']
    fib = r['Fibre (g)']
    cals = r['Calories (kcal)']
    carbs = r['Carbohydrates (g)']
    print(f"  • {name} | Fibre: {fib}g | Cals: {cals} kcal | Carbs: {carbs}g")

