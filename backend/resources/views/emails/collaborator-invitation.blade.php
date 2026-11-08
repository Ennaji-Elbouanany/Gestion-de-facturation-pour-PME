<!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8"><title>Invitation Factura</title></head>
<body>
    <p>Bonjour,</p>
    <p>Vous avez été invité(e) à rejoindre {{ $companyName }} sur Factura en tant que {{ $roleLabel }}.</p>
    <p><a href="{{ $inviteUrl }}">Accepter l'invitation</a></p>
    <p>Ce lien est personnel, utilisable une seule fois et expire après 48 heures. Ne le partagez pas.</p>
    <p>Si vous ne connaissez pas cette entreprise, ignorez cet e-mail.</p>
</body>
</html>
