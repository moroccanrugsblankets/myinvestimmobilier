<style>
    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background-color: #f5f5f5;
    }
    .sidebar {
        background: linear-gradient(180deg, #2c3e50 0%, #34495e 100%);
        min-height: 100vh;
        padding: 0;
        position: fixed;
        left: 0;
        top: 0;
        width: 250px;
        color: white;
        z-index: 1000;
        transition: transform 0.3s ease-in-out;
    }
    .sidebar .logo {
        padding: 20px;
        text-align: center;
        border-bottom: 1px solid rgba(255,255,255,0.1);
    }
    .sidebar .logo h4 {
        margin: 10px 0 5px 0;
        font-size: 18px;
        font-weight: 600;
    }
    .sidebar .logo small {
        color: #bdc3c7;
    }
    .sidebar .nav-link {
        color: rgba(255,255,255,0.8);
        padding: 8px 20px;
        font-size: 0.95em;
        transition: all 0.3s;
        border-left: 3px solid transparent;
    }
    .sidebar .nav-link:hover {
        background-color: rgba(255,255,255,0.1);
        color: white;
        border-left-color: #3498db;
    }
    .sidebar .nav-link.active {
        background-color: rgba(52, 152, 219, 0.2);
        color: white;
        border-left-color: #3498db;
    }
    .sidebar .nav-link i {
        margin-right: 10px;
        width: 20px;
    }
    .main-content {
        margin-left: 250px;
        padding: 30px;
        transition: margin-left 0.3s ease-in-out;
    }
    .logout-btn {
        position: absolute;
        bottom: 20px;
        left: 20px;
        right: 20px;
    }
    
    /* Mobile menu toggle button */
    .menu-toggle {
        display: none;
        position: fixed;
        top: 15px;
        left: 15px;
        z-index: 1001;
        background: #2c3e50;
        color: white;
        border: none;
        border-radius: 5px;
        padding: 10px 15px;
        cursor: pointer;
        box-shadow: 0 2px 5px rgba(0,0,0,0.2);
    }
    .menu-toggle:hover {
        background: #34495e;
    }
    
    /* Overlay for mobile menu */
    .sidebar-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.5);
        z-index: 999;
    }
    
    /* Mobile responsive styles */
    @media (max-width: 1399px) {
        .sidebar {
            transform: translateX(-100%);
        }
        
        .sidebar.active {
            transform: translateX(0);
        }
        
        .main-content {
            margin-left: 0;
            padding: 70px 15px 15px 15px;
        }
        
        .menu-toggle {
            display: block;
        }
        
        .sidebar-overlay.active {
            display: block;
        }
        
        .logout-btn {
            position: relative;
            bottom: auto;
            left: auto;
            right: auto;
            margin: 20px;
        }
    }
</style>
