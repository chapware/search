<?php 
	error_reporting(E_ERROR | E_PARSE);	
	$admin = "e5f6921d9df45435e45c87fdbdb0e4a9";
	$admin_pw = "728275f40d3604c311186ce097d7f505";

	session_start();
	
if (isset($_POST['user']) && isset($_POST['pass'])) {

	$user = $_POST['user'];
	$username = md5($user);
	$pass = $_POST['pass'];
	$password = md5($pass);
	if (($username == $admin) && ($password ==$admin_pw)) {
		$_SESSION['admin'] = $username;
		$_SESSION['admin_pw'] = $password;
	}
	header("Location: admin.php");
} elseif ((isset($_SESSION['admin']) && isset($_SESSION['admin_pw']) &&$_SESSION['admin'] == $admin && $_SESSION['admin_pw'] == $admin_pw ) || (getenv("REMOTE_ADDR")=="")) {

} else {
	
	?>
	<html>
	<head>
	<title>INDSearch Admin Login</title>
		<LINK REL=STYLESHEET HREF="admin.css" TYPE="text/css">
	</head>

	<body>
	<center>
	<br><br>
	
	<fieldset style="width:30%;"><legend><b>INDSearch Admin Login</b></legend>
	<form action="auth.php" method="post">
	
	<table>
	<tr><td>Username</td><td><input type="text" name="user"></td></tr>
	<tr><td>Password</td><td><input type="password" name="pass"></td></tr>
	<tr><td></td><td><input type="submit" value="Log in" id="submit"></td>
	</tr></table>
	</form>
	</fieldset>
	</center>
	</body>
	</html>
	<?php 
	exit();
}


$settings_dir = "../settings";
include "$settings_dir/database.php";

?>