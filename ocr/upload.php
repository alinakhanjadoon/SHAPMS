<!DOCTYPE html>
<html>
<head>
    <title>OCR Upload</title>
</head>
<body>

<h2>Upload Prescription / Report</h2>

<form action="process.php" method="POST" enctype="multipart/form-data">
    <input type="file" name="image" required>
    <button type="submit">Scan OCR</button>
</form>

</body>
</html>