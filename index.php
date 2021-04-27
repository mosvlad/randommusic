<html>
<head>
<?php $title="Random Music";?>
<?php 


$messages_buffer_file = 'messages.json';

$messages_buffer_size = 100;

if ( isset($_POST['content']) and isset($_POST['name']) )
{
    $buffer = fopen($messages_buffer_file, 'r+b');
    flock($buffer, LOCK_EX);
    $buffer_data = stream_get_contents($buffer);
    
    $messages = $buffer_data ? json_decode($buffer_data, true) : array();
    $next_id = (count($messages) > 0) ? $messages[count($messages) - 1]['id'] + 1 : 0;
	
	$message_content = $_POST['content'];
	$name_content = $_POST['name'];
	if ((strlen($message_content) > 256) || ((strlen($name_content) > 32)))
	{
		exit();
	}
	
    $messages[] = array('id' => $next_id, 'time' => time(), 'name' => $_POST['name'], 'content' => $_POST['content']);
    
    if (count($messages) > $messages_buffer_size)
        $messages = array_slice($messages, count($messages) - $messages_buffer_size);

    ftruncate($buffer, 0);
    rewind($buffer);
    fwrite($buffer, json_encode($messages));
    flock($buffer, LOCK_UN);
    fclose($buffer);
    
    // Optional: Append message to log file (file appends are atomic)
    //file_put_contents('chatlog.txt', strftime('%F %T') . "\t" . strtr($_POST['name'], "\t", ' ') . "\t" . strtr($_POST['content'], "\t", ' ') . "\n", FILE_APPEND);
    
    exit();
}

?>

<?php
include('simple_html_dom.php');
function getDonationsHead()
{

$html = file_get_html("https://www.donationalerts.com/widget/lastdonations?alert_type=1,4,6,8,7,10,9,3,2,5,12&limit=1?group_id=1&token=TOKENHERE");

$links = array();
foreach($html->find('div') as $a) {
 $links[] = $a->plaintext;
}

return $links[17];
}

function getDonationsMessage()
{

$html = file_get_html("https://www.donationalerts.com/widget/lastdonations?alert_type=1,4,6,8,7,10,9,3,2,5,12&limit=1?group_id=1&token=TOKENHERE");

$links = array();
foreach($html->find('div') as $a) {
 $links[] = $a->plaintext;
}

return $links[18];
}

?>
<head>
  	<meta charset="UTF-8">
  	<title>Random music</title>
	<meta name="description" content="Random music - Случайная музыка онлайн, просто заходи и слушай!"> 
	<meta name="Keywords" content="Музыка, онлайн, бесплатно, скачать, чат, плейлист, случайно, Music, chat, playlist, random"> 
  	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="yandex-verification" content="f4ff80d20a325806" />
  	<link rel="icon"  type="image/png"   href="img/icon.png">
	<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.1.0/jquery.min.js"></script>
		<script src='https://api.html5media.info/1.1.8/html5media.min.js'></script>
	<script src="../dist/id3-minimized.js" type="text/javascript"></script>
	<script src="js/script.js"></script>
	<script src="https://www.google.com/recaptcha/api.js" async defer></script>
	
	   <script>
       function onSubmit(token) {
         grecaptcha.execute();
       }
     </script>

	<script>
		window.dataLayer = window.dataLayer || [];
		function gtag(){dataLayer.push(arguments);}
		gtag('js', new Date());
		gtag('config', 'UA-141793479-1');
	</script>
	
	<script type="text/javascript">
        // <![CDATA[
        $(document).ready(function(){

            $('ul#messages > li').remove();
            
            $('form').submit(function(){
                var form = $(this);
                var name =  form.find("input[name='name']").val();
                var content =  form.find("input[name='content']").val();
                if(content.length > 256 || name.length > 32)
				{
					alert("Message length max: 256 \nName lenght max: 32");
                    return false;
				}
				if (name == '' || content == '')
				{
					alert("Message and name must be not blank!")
                    return false;
                }
                $.post(form.attr('action'), {'name': name, 'content': content}, function(data, status){
                    $('<li class="pending" />').text(content).prepend($('<small />').text(name)).appendTo('ul#messages');
                    $('ul#messages').scrollTop( $('ul#messages').get(0).scrollHeight );
                    form.find("input[name='content']").val('').focus();
                });
                return false;
            });

            var poll_for_new_messages = function(){
                $.ajax({url: 'messages.json', dataType: 'json', ifModified: true, timeout: 2000, success: function(messages, status){
                    if (!messages)
                        return;

                    $('ul#messages > li.pending').remove();

                    var last_message_id = $('ul#messages').data('last_message_id');
                    if (last_message_id == null)
                        last_message_id = -1;

                    for(var i = 0; i < messages.length; i++)
                    {
                        var msg = messages[i];
                        if (msg.id > last_message_id)
                        {
                            var date = new Date(msg.time * 1000);
                            $('<li/>').prepend($('<big />').text(msg.content)).
                                prepend( $('<small />').text(date.getHours() + ':' + date.getMinutes() + ':' + date.getSeconds() + ' ' + msg.name) ).
                                appendTo('ul#messages');
                            $('ul#messages').data('last_message_id', msg.id);
                        }
                    }
                    

                    $('ul#messages > li').slice(0, -50).remove();
                    $('ul#messages').scrollTop( $('ul#messages').get(0).scrollHeight );
                }});
            };

            poll_for_new_messages();
            setInterval(poll_for_new_messages, 2000);
        });
        // ]]>
    </script>

	
<!— Global site tag (gtag.js) - Google Analytics —>
<script async src="https://www.googletagmanager.com/gtag/js?id=UA-141793479-1"></script>
		<script>
			window.dataLayer = window.dataLayer || [];
			function gtag(){dataLayer.push(arguments);}
			gtag('js', new Date());

			gtag('config', 'UA-141793479-1');
		</script>
  
     <link rel="stylesheet" href="css/style.css">		  
</head>

<body onLoad = "Init();">
	
<br>
<br>

  <div class="container">
 <!---->
    <div class="column center">
        <h1><font color = "#EEEEEE" size = "57">R<font color = "#EEEEEE" size = "57" onclick="tenshi()">a</font>ndom music </font></h1>
        <h6><font color="#EEEEEE">
			<br>
			<hr>Привет, друг. 
			На этом сайте ты можешь слушать случайную музыку и общаться.
			<br> Вопросы и предложения принимаются в чатик в телеге. Все ссылки внизу.
			<br>Всем добра!<hr>
		</font></h6>
    </div>
    <div class="column add-bottom">
        <div id="mainwrap">
		  	
            <div id="audiowrap">
				<br>
				<div class="column center">
				<br>
				<h4 id="nowplaying"><font color = "#FFFFFF" size = "35">TITLE</font></h4><br>
				</div>	
                <div id="audio0">
                  <audio src = ""; autoplay = "" id="audio1" controls="controls" onEnded = "GetNext();">Your browser does not support HTML5 Audio!</audio>
			</div>
            <div id="tracks">
                    <button onClick = "GetNext();" class="button"><span>RANDOM</span></button
                </div>
            </div>
			<input class="spoilerbutton" type="button" value="Show history" onclick="this.value=this.value=='Show history'?'Hide history':'Show history';">
				<div class="spoiler">
					<div id = "tracks_history">
					</div>
			</div>
        </div>

    </div>
	
	
	<br>
	<h1 align = "center"><font color = "#EEEEEE" size = "25">Last donate </font></h1> <br>
	<div id="last_donate" style = "padding: 15px; opacity: 0.85; background: #FFFFFF;">
		<div><font name="last_donate_head" id="last_donate_head" style = 'color: #737577; font-size: 25;'><?php echo getDonationsHead(); ?></font></div><br>
		<div><font name="last_donate_message" id="last_donate_message" style = 'color: #737577; font-size: 15;'><?php echo getDonationsMessage(); ?></font></div>
	</div>	
	</div>

	<h1 align = "center"><font color = "#EEEEEE" size = "40">Lamp chat </font></h1>
	<br>
	<div>
	<div style = "display: flex;">
	<font style = 'color: #EEEEEE; font-size: 25; padding: 5px'>Night/day mode: </font>
		<label class="switch">
			<input type="checkbox" onClick = "changeBg();">
			<span class="slider round"></span>
		</label>
	</div>
	<ul id="messages">
    <li>loading…</li>
	</ul>
	<form id = "chat-form" action="<?= htmlentities($_SERVER['PHP_SELF'], ENT_COMPAT, 'UTF-8'); ?>" method="post">
	
	<div align = "center">
	<label class = "message_input">
		<message>Message:</message>
		<input type="text" name="content" id="content"  autocomplete="off" style="font-size: 15px; padding: 10px;"/>
	<label>	

    <label class = "message_input">
		<message>Name:   </message>
		<input type="text" name="name" id="name" value="Anonymous"  autocomplete="off"  style="font-size: 15px; padding: 10px;"/>
		</label>
		<div class="g-recaptcha"
			data-sitekey="6LfJoc4UAAAAAFavXG_QMR8YnP6bkC8mM3aoTsjK"
			data-callback="onSubmit"
			data-size="invisible">
		</div>
	<button type="submit" class = "button" onClick = "grecaptcha.execute();">Send</button>
	</div>

	</form>
	<br>
</div>
	<div style="text-align-last: center; padding : 25px;">
		<a href = "https://t.me/randommusic_reborn" target="_blank">Telegram Chat</a> <a href = "https://www.donationalerts.com/r/iii_faust_iii" target="_blank" style="padding-left: 25px;">Donate</a><br> <br>
	</div>	
</body>

<div style = "visibility: hidden; position: absolute; width: 100%; top: -10000px; left: 0px; right: 0px; transition: visibility 0s linear 0.3s, opacity 0.3s linear 0s; opacity: 0;" itemscope itemtype="http://schema.org/ImageObject">
  <h2 itemprop="name">logo</h2>
  <img src="img/logo.png" itemprop="contentUrl" />
  <span itemprop="description">Так выглядит сайт.</span>
</div>

</html>
