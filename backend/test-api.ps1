# ============================================
# PassionTrack - Script de test API complet
# ============================================
# Usage : lance ce script depuis le dossier backend/
# avec le serveur PHP actif (php -S 127.0.0.1:8000 -t public)

$baseUrl = "http://127.0.0.1:8000"

Write-Host "=== 1. REGISTER (peut échouer si déjà existant, c'est normal) ===" -ForegroundColor Cyan
try {
    Invoke-RestMethod -Uri "$baseUrl/api/register" -Method Post -ContentType "application/json" -Body '{"email":"test@test.com","username":"testuser","password":"newpassword456"}'
} catch {
    Write-Host "User déjà existant (normal)" -ForegroundColor Yellow
}

Write-Host "`n=== 2. LOGIN ===" -ForegroundColor Cyan
$response = Invoke-RestMethod -Uri "$baseUrl/api/login" -Method Post -ContentType "application/json" -Body '{"email":"test@test.com","password":"newpassword456"}'
$token = $response.token
$refreshToken = $response.refresh_token
Write-Host "Token récupéré : $($token.Substring(0,30))..." -ForegroundColor Green

$headers = @{ "Authorization" = "Bearer $token" }

Write-Host "`n=== 3. REFRESH TOKEN ===" -ForegroundColor Cyan
Invoke-RestMethod -Uri "$baseUrl/api/token/refresh" -Method Post -ContentType "application/json" -Body "{`"refresh_token`":`"$refreshToken`"}"

Write-Host "`n=== 4. GET /api/user ===" -ForegroundColor Cyan
Invoke-RestMethod -Uri "$baseUrl/api/user" -Method Get -Headers $headers

Write-Host "`n=== 5. PUT /api/user ===" -ForegroundColor Cyan
Invoke-RestMethod -Uri "$baseUrl/api/user" -Method Put -ContentType "application/json" -Headers $headers -Body '{"first_name":"Nouri","last_name":"Morouche","language":"fr"}'

Write-Host "`n=== 6. GET /api/categories ===" -ForegroundColor Cyan
Invoke-RestMethod -Uri "$baseUrl/api/categories" -Method Get -Headers $headers

Write-Host "`n=== 7. GET /api/categories/1 ===" -ForegroundColor Cyan
Invoke-RestMethod -Uri "$baseUrl/api/categories/1" -Method Get -Headers $headers

Write-Host "`n=== 8. POST /api/sessions ===" -ForegroundColor Cyan
$session = Invoke-RestMethod -Uri "$baseUrl/api/sessions" -Method Post -ContentType "application/json" -Headers $headers -Body '{"category_id":1,"title":"Test Session","date_start":"2026-08-31 10:00:00","date_end":"2026-08-31 12:00:00","duration":7200,"data":{"altitude_max":3000}}'
$sessionId = $session.id
Write-Host "Session créée avec id: $sessionId" -ForegroundColor Green

Write-Host "`n=== 9. GET /api/sessions ===" -ForegroundColor Cyan
Invoke-RestMethod -Uri "$baseUrl/api/sessions" -Method Get -Headers $headers

Write-Host "`n=== 10. GET /api/sessions/$sessionId ===" -ForegroundColor Cyan
Invoke-RestMethod -Uri "$baseUrl/api/sessions/$sessionId" -Method Get -Headers $headers

Write-Host "`n=== 11. PUT /api/sessions/$sessionId ===" -ForegroundColor Cyan
Invoke-RestMethod -Uri "$baseUrl/api/sessions/$sessionId" -Method Put -ContentType "application/json" -Headers $headers -Body '{"title":"Test Session (modifié)"}'

Write-Host "`n=== 12. GET /api/notifications ===" -ForegroundColor Cyan
Invoke-RestMethod -Uri "$baseUrl/api/notifications" -Method Get -Headers $headers

Write-Host "`n=== 13. POST /api/sessions/$sessionId/analyze (IA - peut prendre 10-30s) ===" -ForegroundColor Cyan
try {
    Invoke-RestMethod -Uri "$baseUrl/api/sessions/$sessionId/analyze" -Method Post -Headers $headers
} catch {
    Write-Host "Erreur analyse IA (vérifier qu'Ollama tourne) : $_" -ForegroundColor Red
}

Write-Host "`n=== 14. DELETE /api/sessions/$sessionId (cleanup) ===" -ForegroundColor Cyan
Invoke-RestMethod -Uri "$baseUrl/api/sessions/$sessionId" -Method Delete -Headers $headers

Write-Host "`n=== TOUS LES TESTS TERMINÉS ===" -ForegroundColor Green
