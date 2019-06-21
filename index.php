<head>
	<?php $title="Random Music";?>
	<link rel="stylesheet" href="css/style.css">
	<!--<script src="js/jquery.js"></script>-->
	<!--<script src="js/swap.js" type="text/javascript"></script>-->
	<!--<script src="js/jquery.min.js" type="text/javascript"></script>-->
	<!--<script src="js/dropzone.js" type="text/javascript"></script>-->
	<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.1.0/jquery.min.js"></script>
	<script src='https://api.html5media.info/1.1.8/html5media.min.js'></script>
	<script src="../dist/id3-minimized.js" type="text/javascript"></script>
	<script src="js/script.js"></script>
	<script async src="https://www.googletagmanager.com/gtag/js?id=UA-141793479-1"></script>
	<script>
		window.dataLayer = window.dataLayer || [];
		function gtag(){dataLayer.push(arguments);}
		gtag('js', new Date());
		gtag('config', 'UA-141793479-1');
	</script>
	
	<meta charset="utf-8">
	<meta name='viewport' content='width=device-width, initial-scale=1.0, maximum-scale=1.0' />
	<title><?php echo $title;?></title>
	<?php 
		// error_reporting(0); 
		// $url= 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
		// $parsedUrl = parse_url($url); parse_str($parsedUrl['query'], $parsedQueryString);
		// $page=0;
		// $lang='eng';
		// if (!empty($_REQUEST['page'])){$page=$_REQUEST['page'];} else {$page='home';}
		// if (!empty($_REQUEST['lang'])){$lang=$_REQUEST['lang'];} else {$lang='en';}
	?>
</head>
<body onLoad = "Init();">
	<div class="bg" id = "bg"></div>
	<div class="cont">
		<div id="form" style="padding: 20px; text-align: center;">
			<hr>
			<a id="title">Random Music</a>
			<hr>
			<br>
			Сейчас играет:<br>
			<a id="nowplaying">Исполнитель - Название трека</a><br><br>
			<div id="audio0"><audio id="audio1" controls autoplay onEnded = "GetNext();"></audio></div><br><br>
			<button id="btn" onClick = "GetNext();">RANDOM</button>
			<br>
			<br>
		</div>
	</div>
	<div class="links">
		<center>
			<a id="icon_lnk" target="_blank" rel="noopener noreferrer" href = "https://t.me/randommusic8081">Telegram</a>
			<a id="icon_lnk" >Upload</a>
			<a id="icon_lnk" >About</a>
		</center>
		<center>
			<div id = "info">
			created by: <a id = "info" target="_blank" rel="noopener noreferrer" href = "https://t.me/Faust_z">Faust</a><br>
			designed by: <a id = "info" target="_blank" rel="noopener noreferrer" href = "https://t.me/expl0si0ntim3r">AFTERLIFE</a>
			</div>	
		</center>	
	</div>
	
</body>