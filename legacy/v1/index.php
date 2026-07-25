<head>
<?php $title="Random Music";?>
<?php 

// Name of the message buffer file. You have to create it manually with read and write permissions for the webserver.
$messages_buffer_file = 'messages.json';
// Number of most recent messages kept in the buffer
$messages_buffer_size = 50;

if ( isset($_POST['content']) and isset($_POST['name']) )
{
    // Open, lock and read the message buffer file
    $buffer = fopen($messages_buffer_file, 'r+b');
    flock($buffer, LOCK_EX);
    $buffer_data = stream_get_contents($buffer);
    
    // Append new message to the buffer data or start with a message id of 0 if the buffer is empty
    $messages = $buffer_data ? json_decode($buffer_data, true) : array();
    $next_id = (count($messages) > 0) ? $messages[count($messages) - 1]['id'] + 1 : 0;
	
	$message_content = $_POST['content'];
	$name_content = $_POST['name'];
	if ((strlen($message_content) > 256) || ((strlen($name_content) > 32)))
	{
		exit();
	}
	
    $messages[] = array('id' => $next_id, 'time' => time(), 'name' => $_POST['name'], 'content' => $_POST['content']);
    
    // Remove old messages if necessary to keep the buffer size
    if (count($messages) > $messages_buffer_size)
        $messages = array_slice($messages, count($messages) - $messages_buffer_size);
    
    // Rewrite and unlock the message file
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
function get_string_between($string, $start, $end){
    $string = ' ' . $string;
    $ini = strpos($string, $start);
    if ($ini == 0) return '';
    $ini += strlen($start);
    $len = strpos($string, $end, $ini) - $ini;
    return substr($string, $ini, $len);
}



?>
<html >
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
            // Remove the "loading…" list entry
            $('ul#messages > li').remove();
            
            $('form').submit(function(){
                var form = $(this);
                var name =  form.find("input[name='name']").val();
                var content =  form.find("input[name='content']").val();
				//console.log(content);
                // Only send a new message if it's not empty (also it's ok for the server we don't need to send senseless messages)
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
                // Append a "pending" message (not yet confirmed from the server) as soon as the POST request is finished. The
                // text() method automatically escapes HTML so no one can harm the client.
                $.post(form.attr('action'), {'name': name, 'content': content}, function(data, status){
                    $('<li class="pending" />').text(content).prepend($('<small />').text(name)).appendTo('ul#messages');
                    $('ul#messages').scrollTop( $('ul#messages').get(0).scrollHeight );
                    form.find("input[name='content']").val('').focus();
                });
                return false;
            });
            
            // Poll-function that looks for new messages
            var poll_for_new_messages = function(){
                $.ajax({url: 'messages.json', dataType: 'json', ifModified: true, timeout: 2000, success: function(messages, status){
                    // Skip all responses with unmodified data
                    if (!messages)
                        return;
                    
                    // Remove the pending messages from the list (they are replaced by the ones from the server later)
                    $('ul#messages > li.pending').remove();
                    
                    // Get the ID of the last inserted message or start with -1 (so the first message from the server with 0 will
                    // automatically be shown).
                    var last_message_id = $('ul#messages').data('last_message_id');
                    if (last_message_id == null)
                        last_message_id = -1;
                    
                    // Add a list entry for every incomming message, but only if we not already inserted it (hence the check for
                    // the newer ID than the last inserted message).
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
                    
                    // Remove all but the last 50 messages in the list to prevent browser slowdown with extremely large lists
                    // and finally scroll down to the newes message.
                    $('ul#messages > li').slice(0, -50).remove();
                    $('ul#messages').scrollTop( $('ul#messages').get(0).scrollHeight );
                }});
            };
            
            // Kick of the poll function and repeat it every two seconds
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

        <h1><font color = "#EEEEEE" size = "57">Random music</font></h1>
	<!---<h1><font color = "#EE0000" size = "57">Судя по всему РКН начал бороться с моим серваком. 
<br>Так что мы скоро переедем. Чтобы оставаться на связи заходите в ТГ 
<br><a href="https://t.me/randommusic_reborn"> ссылка </a>
<br> там будет актуальная информация по перезду</font></h1>--->
        <h6><font color="#EEEEEE">
			<br>
			<hr>Привет, друг. 
			На этом сайте ты можешь слушать случайную музыку и общаться.
			<br> Вопросы и предложения принимаются в чатик в телеге. Все ссылки внизу.
			<br> <font color = "#EE0000">Чтобы оставаться на связи заходите в ТГ <a href="https://t.me/randommusic_reborn"> ссылка </a></font>
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
	<!---<h1 align = "center"><font color = "#EEEEEE" size = "25">Last donate </font></h1> <br>
	<div id="last_donate" style = "padding: 15px; opacity: 0.85; background: #FFFFFF;">
		<div><font name="last_donate_head" id="last_donate_head" style = 'color: #737577; font-size: 25;'></font></div><br>
		<div><font name="last_donate_message" id="last_donate_message" style = 'color: #737577; font-size: 15;'></font></div>
	</div>	
	</div>--->

	<h1 align = "center"><font color = "#EEEEEE" size = "40">Lamp chat </font></h1>
	<br>
	<div>
	<!-- <div style = "display: flex;">
	<font style = 'color: #EEEEEE; font-size: 25; padding: 5px'>Night/day mode: </font>
		<label class="switch">
			<input type="checkbox" onClick = "changeBg();">
			<span class="slider round"></span>
		</label>
	</div> -->
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
		<a href = "https://t.me/randommusic_reborn" target="_blank">Telegram Chat</a><br> <br>
	</div>	
</body>

<div style = "visibility: hidden; position: absolute; width: 100%; top: -10000px; left: 0px; right: 0px; transition: visibility 0s linear 0.3s, opacity 0.3s linear 0s; opacity: 0;" itemscope itemtype="http://schema.org/ImageObject">
  <h2 itemprop="name">logo</h2>
  <img src="img/logo.png" itemprop="contentUrl" />
  <span itemprop="description">Так выглядит сайт.</span>
</div>

</html>
