# Data Storage Layout

This directory stores runtime artifacts created by the application.

## Base folders

- `receipts` -> year/month/date/bill_receipts
- `reports` -> radiologist/year/month/main_category/date/reports
- `expenses` -> year/month/category/date/proof
- `professional_charges` -> doctor/year/month/excel_sheet
- `monthly_reports`
- `daily_reports`

All nested folders are auto-created by helper functions in `includes/functions.php`.
