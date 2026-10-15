<!DOCTYPE html>
<html>
<head>
    <title>Login Test</title>
    <style>
        body { font-family: Arial; max-width: 500px; margin: 50px auto; padding: 20px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
        button { background: #007bff; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; }
        button:hover { background: #0056b3; }
        #result { margin-top: 20px; padding: 15px; border: 1px solid #ddd; border-radius: 4px; background: #f9f9f9; }
        .success { color: green; }
        .error { color: red; }
    </style>
</head>
<body>
    <h1>Login Test</h1>
    <form id="loginForm">
        <div class="form-group">
            <label>Email:</label>
            <input type="email" id="email" value="samasdmadrisad556@gmail.com" required>
        </div>
        <div class="form-group">
            <label>Password:</label>
            <input type="password" id="password" value="password123" required>
        </div>
        <button type="submit">Login</button>
    </form>
    
    <div id="result">
        <p>Waiting for login attempt...</p>
    </div>

    <script>
    document.getElementById('loginForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const email = document.getElementById('email').value;
        const password = document.getElementById('password').value;
        const resultDiv = document.getElementById('result');
        
        resultDiv.innerHTML = '<p>⏳ Logging in...</p>';
        
        console.log('Attempting login with:', { email, password });
        
        try {
            // IMPORTANT: Change this URL based on where your login.php is
            // If login.php is in the root: 'login.php'
            // If login.php is in a subfolder: '../login.php' or './login.php'
            const response = await fetch('api/login.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ 
                    email: email, 
                    password: password 
                })
            });
            
            console.log('Response status:', response.status);
            
            // Check if response is OK
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            // Get the response text first to debug
            const responseText = await response.text();
            console.log('Raw response:', responseText);
            
            // Try to parse JSON
            let data;
            try {
                data = JSON.parse(responseText);
            } catch (e) {
                throw new Error(`Invalid JSON response: ${responseText.substring(0, 100)}...`);
            }
            
            console.log('Parsed response:', data);
            
            if (data.success) {
                resultDiv.innerHTML = `
                    <div class="success">
                        <h3>✅ Login Successful!</h3>
                        <p><strong>User:</strong> ${data.user.full_name}</p>
                        <p><strong>Email:</strong> ${data.user.email}</p>
                        <p><strong>Role:</strong> ${data.user.role}</p>
                        <p><strong>Redirecting to:</strong> ${data.redirect}</p>
                        <p>Redirecting in 2 seconds...</p>
                    </div>
                `;
                
                // Redirect after 2 seconds
                setTimeout(() => {
                    window.location.href = data.redirect;
                }, 2000);
            } else {
                resultDiv.innerHTML = `
                    <div class="error">
                        <h3>❌ Login Failed</h3>
                        <p>${data.message || 'Unknown error'}</p>
                    </div>
                `;
            }
        } catch (error) {
            console.error('Error:', error);
            resultDiv.innerHTML = `
                <div class="error">
                    <h3>❌ Error</h3>
                    <p>${error.message}</p>
                    <p style="font-size: 12px; color: #666;">Check the console for more details</p>
                </div>
            `;
        }
    });
    </script>
</body>
</html>