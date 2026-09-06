# SHAPMS — Smart Hospital and Pharmacy Management System

A multi-module web application for managing hospital operations — patient appointments, pharmacy billing, staff scheduling, and department reporting — built as a Final Year Project.

**Branding:** Zaman Medical

---

## Tech Stack

- **Backend:** PHP (prepared statements throughout for SQL safety)
- **Database:** MySQL
- **Frontend:** HTML, CSS, JavaScript, Bootstrap
- **Data visualization:** Chart.js
- **Async interactions:** AJAX (chat system, billing status updates, dashboard live data)
- **External integrations:** OCR.space API and Google Gemini (gemini-2.0-flash) for lab report scanning

---

## Modules & Features

### Admin
- Staff management (add/edit receptionists and doctors) with prepared statements and temporary password generation
- Reports dashboard: pharmacy revenue, appointment overviews, and inventory — visualized with Chart.js

### Receptionist
- Appointment queue management
- Walk-in patient booking
- Doctor availability panel

### Doctor
- Shift management module
- Dashboard with parsed lab report modals (abnormal labs, critical findings, diagnosis, urgent actions — pulled from structured JSON)

### Nurse
- Assignment and bed status tracking
- Nursing notes display
- Real-time nurse–doctor chat via AJAX

### Pharmacist
- Auto-generated bills on prescription completion
- Flat 10% discount logic
- Mark-as-paid flow via AJAX
- Custom warm peach/blush visual theme

### Department Head / Lab
- Staff management and reporting dashboard
- Dark navy themed portal for the Laboratory department

### Patient
- Appointment booking page with slot selection
- Visual doughnut chart summary of appointment history

### Lab Report Scanner (OCR + AI)
- Scans uploaded lab reports via OCR.space
- Passes cleaned text to Google Gemini for structured extraction of abnormal findings
- Custom PHP pre-processing layer to clean noisy OCR output before sending to the AI model

---

## Academic Deliverables

- Entity Relationship Diagrams (ERD)
- System Sequence Diagrams (UML-style, standard notation)
- Data Flow Diagrams (DFDs), rendered via a custom Python SVG pipeline with standard DFD notation

---

## Setup

1. Import the database schema (see `/sql` or root-level `.sql` file if present).
2. Configure database credentials in the relevant config file (**not included in this repo for security** — see `.gitignore`).
3. Place the project folder inside your local server's web root (e.g. XAMPP's `htdocs`).
4. Start Apache and MySQL via your local server control panel.
5. Access the project at `http://localhost/hospital` (or your configured folder name).

---

## Notes

This project was built incrementally across multiple modules, with iterative bug fixes and UI redesigns per department (pharmacist, patient, doctor, nurse, lab). Some earlier debugging work included resolving bind_param type mismatches, empty data table issues, and simplifying the appointment approval flow to auto-approval.
