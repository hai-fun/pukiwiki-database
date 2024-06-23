<?php
// PukiWiki - Yet another WikiWikiWeb clone
// paint.inc.php
// Copyright 2002-2024 PukiWiki Development Team
// License: GPL v2 or (at your option) any later version
//
// Paint plugin

/*
 * Usage
 *  #paint(width,height)
 * パラメータ
 *  キャンバスの幅と高さ
 */

// 挿入する位置 1:欄の前 0:欄の後
define('PAINT_INSERT_INS', 0);

// デフォルトの描画領域の幅と高さ
define('PAINT_DEFAULT_WIDTH', 360);
define('PAINT_DEFAULT_HEIGHT', 360);

// 描画領域の幅と高さの制限値
define('PAINT_MAX_WIDTH', 960);
define('PAINT_MAX_HEIGHT', 960);

//コメントの挿入フォーマット
define('PAINT_NAME_FORMAT', '[[$name]]');
define('PAINT_MSG_FORMAT', '$msg');
define('PAINT_NOW_FORMAT', '&new{$now};');
//メッセージがある場合
define('PAINT_FORMAT', "\x08MSG\x08 -- \x08NAME\x08 \x08NOW\x08");
//メッセージがない場合
define('PAINT_FORMAT_NOMSG', "\x08NAME\x08 \x08NOW\x08");

define('PAINT_FABRIC_JS_CDN', "https://cdnjs.cloudflare.com/ajax/libs/fabric.js/5.3.1/fabric.min.js");

function plugin_paint_init() {
	global $head_tags;

	$head_tags[] = '<script src="' . PAINT_FABRIC_JS_CDN . '"></script>';
}

function plugin_paint_action()
{
	global $vars, $_paint_messages;

	$script = get_base_uri();
	if (PKWK_READONLY) die_message('PKWK_READONLY prohibits editing');
	
	//戻り値を初期化
	$retval['msg'] = $_paint_messages['msg_title'];
	$retval['body'] = '';

	if (array_key_exists('attach_file', $_FILES) && array_key_exists('refer', $vars)) {
		$file = $_FILES['attach_file'];

		// ページ名はエンコードしてから送信させるようにした。
		$vars['page'] = $vars['refer'] = decode($vars['refer']);

		$filename = $vars['filename'];
		$filename = mb_convert_encoding($filename, SOURCE_ENCODING, 'auto');

		//ファイル名置換
		$attachname = preg_replace('/^[^\.]+/', $filename, $file['name']);
		//すでに存在した場合、 ファイル名に'_0','_1',...を付けて回避(姑息)
		$count = 0;
		while (file_exists(UPLOAD_DIR . encode($vars['refer']) . '_' . encode($attachname))) {
			$attachname = preg_replace('/^[^\.]+/', $filename . '_' . $count++, $file['name']);
		}

		$file['name'] = $attachname;

		if (!exist_plugin('attach') || !function_exists('attach_upload')) {
			return array('msg' => 'attach.inc.php not found or not correct version.');
		}

		$retval = attach_upload($file, $vars['refer'],TRUE);
		if ($retval['result'] == TRUE) {
			$retval = paint_insert_ref($file['name']);
		}
	} else {
		$message = '';
		$page_uri = get_base_uri();
		if (array_key_exists('refer', $vars)) {
			$page_uri = get_page_uri($vars['refer']);
			$s_refer = htmlsc($vars['refer']);
		}
		$link = "<p><a href=\"$page_uri\">$s_refer</a></p>";;

		//XSS脆弱性問題 - 外部から来た変数をエスケープ
		$width = empty($vars['width']) ? PAINT_DEFAULT_WIDTH : $vars['width'];
		$height = empty($vars['height']) ? PAINT_DEFAULT_HEIGHT : $vars['height'];
		$f_w = (is_numeric($width) && $width > 0) ? $width : PAINT_DEFAULT_WIDTH;
		$f_h = (is_numeric($height) && $height > 0) ? $height : PAINT_DEFAULT_HEIGHT;
		$f_refer = array_key_exists('refer', $vars) ? encode($vars['refer']) : '';
		$f_digest = array_key_exists('digest', $vars) ? htmlsc($vars['digest']) : '';
		$f_no = (array_key_exists('paint_no', $vars) && is_numeric($vars['paint_no'])) ?
			$vars['paint_no'] + 0 : 0;

		if ($f_w > PAINT_MAX_WIDTH)
			$f_w = PAINT_MAX_WIDTH;
		
		if ($f_h > PAINT_MAX_HEIGHT)
			$f_h = PAINT_MAX_HEIGHT;

		$retval['body'] .= <<<EOD
<div>
	$link
	$message
	<div class="canvas-wrapper">
		<div id="canvas_menu">
			<button id="clear">{$_paint_messages['btn_clear']}</button> <button id="undo">{$_paint_messages['btn_undo']}</button> <button id="redo">{$_paint_messages['btn_redo']}</button>
			<button id="save">{$_paint_messages['btn_save']}</button> <button id="load">{$_paint_messages['btn_load']}</button>
			<br>
			<button id="shape">{$_paint_messages['btn_square']}</button> <button id="circle">{$_paint_messages['btn_circle']}</button> <button id="text">テキスト</button>|
			<button id="mode">{$_paint_messages['btn_selectmode']}</button>
			<hr>
			<span class="draw_mode">
				<button id="pen">{$_paint_messages['btn_pen']}</button> <button id="eraser">{$_paint_messages['btn_eraser']}</button>
				<input type="range" id="width" value="5" min="1" max="50"> <input type="text" id="width_text" value="5" size="1">
			</span>
			<span class="select_mode" style="display: none;">
				<button id="clone">{$_paint_messages['btn_clone']}</button> <button id="delete">{$_paint_messages['btn_delete']}</button>
			</span>
			<input type="color" id="color" value="#000000"> <input type="text" id="color_text" value="#000000" size="5">
		</div>
		
		<canvas id="canvas" width="$f_w" height="$f_h" style="border: 1px solid black;border-color: black;" data-name="paint$f_no"></canvas>
		
		<hr>
		<input type="text" id="name" value="" size="20" placeholder="{$_paint_messages['field_name']}">
		<input type="text" id="comment" value="" size="40" placeholder="{$_paint_messages['field_comment']}">
		<input type="text" id="filename" value="paint" size="20" placeholder="{$_paint_messages['field_filename']}">
		<input id="send" type="submit" value="{$_paint_messages['btn_insert']}">
	</div>

	<script>
	let drawMode = 'pen';
	
	let penWidth = 5;
	let eraserWidth = 10;

	const canvas = new fabric.Canvas('canvas');
	canvas.backgroundColor = 'white';
	canvas.isDrawingMode = true;

	let state = [];
	let current = 0;
	
	canvas.on('path:created', function() {
		const obj = canvas.toJSON();
		const json = JSON.stringify(obj);
		state.push(json);
		current = state.length;
	});
	
	document.getElementById('send').addEventListener('click', function() {
		const dataURL = canvas.toDataURL({
			width: canvas.width, height: canvas.height,
			top: 0, left: 0,
			format: 'png',
		});
	
		let form = document.createElement('form');
		form.method = 'post';
		form.enctype = 'multipart/form-data';
		form.action = '$script';
		form.style.display = 'none';
		document.body.appendChild(form);
	
		let file = new File([dataURLtoBlob(dataURL)], 'paint$f_no.png', {
			type: 'image/png',
			lastModified: Date.now(),
		});
	
		let dataTransfer = new DataTransfer();
		dataTransfer.items.add(file);

		appendFileInput(form, 'attach_file', dataTransfer.files);
		appendInput(form, 'hidden', 'filename', document.getElementById('filename').value);
		appendInput(form, 'hidden', 'refer', '$f_refer');
		appendInput(form, 'hidden', 'digest', '$f_digest');
		appendInput(form, 'hidden', 'plugin', 'paint');
		appendInput(form, 'hidden', 'yourname', document.getElementById('name').value);
		appendInput(form, 'hidden', 'msg', document.getElementById('comment').value);
	
		form.submit();
	});
	
	function appendInput(form, type, name, value) {
		let input = document.createElement('input');
		input.type = type;
		input.name = name;
		input.value = value;
		form.appendChild(input);
	}

	function appendFileInput(form, name, file) {
		let input = document.createElement('input');
		input.type = 'file';
		input.name = name;
		input.files = file;
		form.appendChild(input);
	}

	function dataURLtoBlob(dataURL) {
		let bytes = atob(dataURL.split(',')[1]);
		let mimeType = dataURL.split(',')[0].split(':')[1].split(';')[0];
		let buf = new ArrayBuffer(bytes.length);
		let arr = new Uint8Array(buf);
		for (let i = 0; i < bytes.length; i++) {
			arr[i] = bytes.charCodeAt(i);
		}
		return new Blob([buf], {type: mimeType});
	}
	
	document.getElementById('mode').addEventListener('click', function() {
		canvas.isDrawingMode = !canvas.isDrawingMode;
		if (canvas.isDrawingMode) {
			this.textContent = '{$_paint_messages['btn_selectmode']}';
			let drawMenu = document.getElementsByClassName('draw_mode');
			for (let i = 0; i < drawMenu.length; i++) {
				drawMenu[i].style.display = 'inline';
			}
			let selectMenu = document.getElementsByClassName('select_mode');
			for (let i = 0; i < selectMenu.length; i++) {
				selectMenu[i].style.display = 'none';
			}
		} else {
			this.textContent = '{$_paint_messages['btn_drawmode']}';
			let drawMenu = document.getElementsByClassName('draw_mode');
			for (let i = 0; i < drawMenu.length; i++) {
				drawMenu[i].style.display = 'none';
			}
			let selectMenu = document.getElementsByClassName('select_mode');
			for (let i = 0; i < selectMenu.length; i++) {
				selectMenu[i].style.display = 'inline';
			}
		}
	});
	
	document.getElementById('color').addEventListener('input', function() {
		const color = this.value;
		document.getElementById('color_text').value = color;
		canvas.freeDrawingBrush.color = color;
	});
	
	document.getElementById('color_text').addEventListener('input', function() {
		const color = this.value;
		document.getElementById('color').value = color;
		canvas.freeDrawingBrush.color = color;
	});
	
	document.getElementById('width').addEventListener('input', function() {
		const width = this.value;
		document.getElementById('width_text').value = width;
		canvas.freeDrawingBrush.width = parseInt(width, 10);
		if (drawMode === 'pen') {
			penWidth = parseInt(width, 10);
		} else {
			eraserWidth = parseInt(width, 10);
		}
	});
	
	document.getElementById('width_text').addEventListener('input', function() {
		const width = this.value;
		document.getElementById('width').value = width;
		canvas.freeDrawingBrush.width = parseInt(width, 10);
		if (drawMode === 'pen') {
			penWidth = parseInt(width, 10);
		} else {
			eraserWidth = parseInt(width, 10);
		}
	});

    function paint_undo() {
        if (current === 0) return;
        current -= 1;
        canvas.loadFromJSON(state[current], canvas.renderAll.bind(canvas));
    }

    function paint_redo() {
        if (current === state.length - 1) return;
        current += 1;
        canvas.loadFromJSON(state[current], canvas.renderAll.bind(canvas));
    }

	function paint_clone() {
		const activeObject = canvas.getActiveObject();
		if (activeObject) {
			activeObject.clone(function(cloned) {
				canvas.discardActiveObject();
				cloned.set({
					left: cloned.left + 10,
					top: cloned.top + 10,
					evented: true,
				});
				if (cloned.type === 'activeSelection') {
					cloned.canvas = canvas;
					cloned.forEachObject(function(obj) {
						canvas.add(obj);
					});
					cloned.setCoords();
				} else {
					canvas.add(cloned);
				}
				canvas.setActiveObject(cloned);
				canvas.requestRenderAll();
			});
		}
	}

	function paint_delete() {
		const activeObject = canvas.getActiveObject();
		if (activeObject) {
			canvas.remove(activeObject);
		}
	}

	function paint_clear() {
		canvas.clear();
	}

	function paint_save() {
		const obj = canvas.toJSON();
		const json = JSON.stringify(obj);
		const blob = new Blob([json], {type: 'application/json'});
		const url = URL.createObjectURL(blob);
		const a = document.createElement('a');
		a.href = url;
		a.download = 'data.json';
		a.click();
	}

	function paint_load() {
		const input = document.createElement('input');
		input.type = 'file';
		input.accept = 'application/json';
		input.onchange = function() {
			const file = input.files[0];
			const reader = new FileReader();
			reader.onload = function() {
				const json = reader.result;
				canvas.loadFromJSON(json, canvas.renderAll.bind(canvas));
			};
			reader.readAsText(file);
		};
		input.click();
	}
	
	document.getElementById('clone').addEventListener('click', paint_clone);
	document.getElementById('delete').addEventListener('click', paint_delete);
    document.getElementById('undo').addEventListener('click', paint_undo);
    document.getElementById('redo').addEventListener('click', paint_redo);
	document.getElementById('clear').addEventListener('click', paint_clear);
	document.getElementById('save').addEventListener('click', paint_save);
	document.getElementById('load').addEventListener('click', paint_load);
	
	document.getElementById('pen').addEventListener('click', function() {
		canvas.freeDrawingBrush = new fabric.PencilBrush(canvas);
		canvas.freeDrawingBrush.color = document.getElementById('color').value;
		canvas.freeDrawingBrush.width = penWidth;
	
		drawMode = 'pen';
		document.getElementById('width').value = penWidth;
		document.getElementById('width_text').value = penWidth;
	});
	
	document.getElementById('eraser').addEventListener('click', function() {
		canvas.freeDrawingBrush = new fabric.PencilBrush(canvas);
		canvas.freeDrawingBrush.color = 'white';
		canvas.freeDrawingBrush.width = eraserWidth;
	
		drawMode = 'eraser';
		document.getElementById('width').value = eraserWidth;
		document.getElementById('width_text').value = eraserWidth;
	});
	
	document.getElementById('text').addEventListener('click', function() {
		const text = new fabric.IText('テキスト', {
			left: 100,
			top: 100,
			fill: document.getElementById('color').value,
			fontSize: 20,
		});
		canvas.add(text);
	});
	
	document.getElementById('shape').addEventListener('click', function() {
		const rect = new fabric.Rect({
			left: 100,
			top: 100,
			fill: document.getElementById('color').value,
			width: 100,
			height: 100
		});
		canvas.add(rect);
	});
	
	document.getElementById('circle').addEventListener('click', function() {
		const circle = new fabric.Circle({
			left: 100,
			top: 100,
			fill: document.getElementById('color').value,
			radius: 50
		});
		canvas.add(circle);
	});
	
	document.addEventListener('keydown', function(e) {
		const activeObject = canvas.getActiveObject();

		if (e.ctrlKey) {
			switch (e.key) {
				case 'z':
					paint_undo();
					break;
				case 'y':
					paint_redo();
					break;
				case 's':
					paint_save();
					break;
				case 'd':
					paint_clone();
					break;
				case 'c':
					if (activeObject) {
						let json = JSON.stringify(activeObject);
						navigator.clipboard.writeText(json);
					}
					break;
				case 'v':
					navigator.clipboard.readText().then(text => {
						let obj = JSON.parse(text);
						fabric.util.enlivenObjects([obj], ([obj]) => {
							canvas.add(obj);
						});
					});
					break;
				case 'x':
					if (activeObject) {
						let json = JSON.stringify(activeObject);
						navigator.clipboard.writeText(json);
						canvas.remove(activeObject);
					}
					break;
			}
		}

		if (activeObject) {
			switch (e.key) {
				case 'Delete':
					paint_delete();
					break;
				case 'ArrowUp':
					activeObject.top -= 1;
					canvas.renderAll();
					break;
				case 'ArrowDown':
					activeObject.top += 1;
					canvas.renderAll();
					break;
				case 'ArrowLeft':
					activeObject.left -= 1;
					canvas.renderAll();
					break;
				case 'ArrowRight':
					activeObject.left += 1;
					canvas.renderAll();
					break;
			}
		}
	});
	</script>
</div>
EOD;
	}
	return $retval;
}

function plugin_paint_convert() {
	global $vars, $digest;
	global $_paint_messages;
	static $numbers = array();

	$script = get_base_uri();
	if (PKWK_READONLY) return ''; // Show nothing

	if (!array_key_exists($vars['page'], $numbers))
	{
		$numbers[$vars['page']] = 0;
	}
	$paint_no = $numbers[$vars['page']]++;

	//戻り値
	$ret = '';

	//文字列を取得
	$width = $height = 0;
	$args = func_get_args();
	if (count($args) >= 2) {
		$width = array_shift($args);
		$height = array_shift($args);
	}
	if (!is_numeric($width) || $width <= 0) {
		$width = PAINT_DEFAULT_WIDTH;
	}
	if (!is_numeric($height) || $height <= 0) {
		$height = PAINT_DEFAULT_HEIGHT;
	}

	//XSS脆弱性問題 - 外部から来た変数をエスケープ
	$f_page = htmlsc($vars['page']);

	$max = sprintf($_paint_messages['msg_max'], PAINT_MAX_WIDTH, PAINT_MAX_HEIGHT);

	$ret = <<<EOD
<form action="$script" method="post">
<div>
<input type="hidden" name="paint_no" value="$paint_no" />
<input type="hidden" name="digest" value="$digest" />
<input type="hidden" name="plugin" value="paint" />
<input type="hidden" name="refer" value="$f_page" />
<input type="text" name="width" size="3" value="$width" />
x
<input type="text" name="height" size="3" value="$height" />
$max
<input type="submit" value="{$_paint_messages['btn_submit']}" />
</div>
</form>
EOD;
	return $ret;
}

function paint_insert_ref($filename) {
	global $vars, $now, $do_backup;
	global $_paint_messages, $_no_name;

	$ret['msg'] = $_paint_messages['msg_title'];

	$msg = mb_convert_encoding(rtrim($vars['msg']), SOURCE_ENCODING, 'auto');
	$name = mb_convert_encoding($vars['yourname'], SOURCE_ENCODING, 'auto');

	$msg  = str_replace('$msg', $msg, PAINT_MSG_FORMAT);
	$name = ($name == '') ? $_no_name : $vars['yourname'];
	$name = ($name == '') ? '' : str_replace('$name', $name, PAINT_NAME_FORMAT);
	$now  = str_replace('$now', $now, PAINT_NOW_FORMAT);

	$msg = trim($msg);
	$msg = ($msg == '') ?
		PAINT_FORMAT_NOMSG :
		str_replace("\x08MSG\x08", $msg, PAINT_FORMAT);
	$msg = str_replace("\x08NAME\x08", $name, $msg);
	$msg = str_replace("\x08NOW\x08", $now, $msg);

	//ブロックに食われないように、#clearの直前に\nを2個書いておく
	$msg = "#ref($filename,wrap,around)\n" . trim($msg) . "\n\n" .
		"#clear\n";

	$postdata_old = get_source($vars['refer']);
	$postdata = '';
	$paint_no = 0; //'#paint'の出現回数
	foreach ($postdata_old as $line) {
		if (!PAINT_INSERT_INS) {
			$postdata .= $line;
		}
		if (preg_match('/^#paint/i', $line)) {
			if ($paint_no == $vars['paint_no']) {
				$postdata .= $msg;
			}
			$paint_no++;
		}
		if (PAINT_INSERT_INS) {
			$postdata .= $line;
		}
	}

	// 更新の衝突を検出
	if (md5(join('', $postdata_old)) !== $vars['digest']) {
		$ret['msg'] = $_paint_messages['msg_title_collided'];
		$ret['body'] = $_paint_messages['msg_collided'];
	}

	page_write($vars['refer'], $postdata);

	return $ret;
}
