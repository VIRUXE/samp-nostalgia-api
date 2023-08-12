<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

$app->get('/serverlog[/{lineCount}]', function (Request $request, Response $response, array $args): Response {
    $logPath = '/home/samp/servidor/server_log.txt';

    if (!file_exists($logPath)) return $response->withStatus(404)->withHeader('Content-Type', 'text/plain')->getBody()->write('Log file not found.');

    $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    // If a line count is provided (either by default or in the path), get the last 'n' lines.
    $lineCount = $args['lineCount'] ?? 50;
    $lines     = array_slice($lines, -$lineCount);
    $params    = $request->getQueryParams();

    $groupedLogs = [];
    $currentTimestamp = '';
    foreach ($lines as $line) {
        if (preg_match('/\[\d{2}:\d{2}:\d{2}\]/', $line, $matches)) {
            $currentTimestamp = $matches[0];
        }

        // If there's no timestamp at the start of the line, it's treated as a continuation of the previous log entry.
        if ($currentTimestamp) {
            $groupedLogs[$currentTimestamp][] = $line;
        }
    }

    // Check for timestamp in query parameters
    $sinceTimestamp = $params['since'];

    if ($sinceTimestamp) {
        $groupedLogs = array_filter($groupedLogs, function($key) use ($sinceTimestamp) {
            return strcmp($key, "[$sinceTimestamp]") > 0;
        }, ARRAY_FILTER_USE_KEY);
    } 

    // Flatten the grouped logs back into an array of lines
    $lines = [];
    foreach ($groupedLogs as $timestamp => $group) {
        $lines = array_merge($lines, $group);
    }
    
    $redactedLines = array_map(function($line) {
        $line = preg_replace('/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/', '[REDACTED]', $line);
        $line = preg_replace('/-?\d+\.\d+\s*,?\s*-?\d+\.\d+\s*,?\s*-?\d+\.\d+/', '[COORDS-REDACTED]', $line);
        $line = preg_replace('/(\[DEFFAIL\].*code\s*)\d+/', '${1}[CODE-REDACTED]', $line);
        if (strpos($line, 'Setting server password to:') !== false) return null;

        return $line;
    }, $lines);

    if ($params['format'] === 'raw') {
        // Return just the redacted logs joined by a newline for raw format
        $response->getBody()->write(join("\n", $redactedLines));
        return $response->withHeader('Content-Type', 'text/plain');
    }

    function tagToColor($tag) {
        if (!ctype_upper($tag)) return '000000';
        $hash = crc32($tag);
        $color = dechex($hash & 0xffffff);
        while (strlen($color) < 6) $color = '0' . $color;
        return $color;
    }

    $styledOutput = '<style>
        body { 
            font-family: Verdana, sans-serif; 
            font-size: 12px;
            color: #E0E0E0;
            background-color: black;
            margin: 0;
        }
        .log-entry { 
            display: flex; 
            align-items: center; 
            padding: 6px 0;
            transition: background-color 0.3s;
            opacity: 0;
            transition: opacity 0.5s;
        }
        .log-entry.visible { 
            opacity: 1;
        }
        .log-entry:nth-child(even) {
            background-color: #121212;
        }
        .log-entry:nth-child(odd) {
            background-color: #181818; 
        }
        .log-entry:hover {
            background-color: #222222;
        }
        .tag { 
            width: 16px; 
            height: 16px; 
            border-radius: 50%; 
            margin: 0 10px; 
            display: inline-block;
        }
        .debug-entry {
            color: yellow;
        }

        .loading-arrow {
            width: 50px;
            height: 50px;
            border: 4px solid transparent;
            border-top-color: #fff;  /* Change this to match the color of your arrow */
            border-left-color: #fff; /* Change this to match the color of your arrow */
            border-radius: 50%;
            position: fixed;  /* This line changed from absolute to fixed */
            top: 10px;        /* Adjust this as per your preference */
            right: 10px;      /* Adjust this as per your preference */
            animation: spin 1s linear infinite;
            display: none;
            opacity: 0;
            transition: opacity 0.5s ease;
        }
        .copy-icon {
            display: inline-block;
            margin-left: 10px;
            cursor: pointer;
            opacity: 0.5;
            transition: opacity 0.3s;
        }
        
        .copy-icon:hover {
            opacity: 1;
        }

        @keyframes spin {
            100% {
                transform: rotate(360deg);
            }
        }
    </style>
    <div class="loading-arrow"></div>
    ';

    foreach ($redactedLines as $line) {
        preg_match('/\[\d{2}:\d{2}:\d{2}\]\s+\[(.*?)\]/', $line, $matches);
        $tag   = $matches[1] ?? '';
        $color = tagToColor($tag);

        if ($tag === 'chat') continue;

        $debugClass = $tag === 'debug' ? ' debug-entry' : '';  // Add debug-entry class if tag is "debug"

        $styledOutput .= "<div class=\"log-entry visible$debugClass\">
                            <div class=\"tag\" style=\"background-color: #$color\"></div>
                            $line
                            <span class=\"copy-icon\">📋</span>
                        </div>";
    }

    // Add JS to scroll to the bottom
    $styledOutput .= '<script>
    const loginAlertSound = new Audio(\'join.wav\');

    document.addEventListener(\'click\', function(e) {
        if (e.target && e.target.classList.contains(\'copy-icon\')) {
            const textToCopy = e.target.previousSibling.nodeValue;
            copyToClipboard(textToCopy);
        }
    });
    
    function copyToClipboard(text) {
        const textArea = document.createElement("textarea");
        textArea.value = text;
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand(\'Copy\');
        textArea.remove();
    }

    if (Notification.permission !== \'granted\') Notification.requestPermission();
    
    function displayNotification(message) {
        if (Notification.permission !== \'granted\') {
            Notification.requestPermission();
        } else {
            const notification = new Notification(\'Scavenge Nostalgia\', {
                icon: \'favicon.png\',
                body: message,
            });
    
            // Optionally, add an onclick event handler
            notification.onclick = function () {
                window.focus();
            };
        }
    }

    // When you receive a log update...
    function handleLogUpdate(logLine) {
        // Check if the log line indicates a player login
        if (logLine.includes(\'[ACCOUNTS]\') && logLine.includes(\'logou.\')) {
            loginAlertSound.play();
            
            const playerName = logLine.split(\'[ACCOUNTS]\')[1].split(\'(\')[0].trim();
            displayNotification(`${playerName} entrou no servidor!`);
        }
    }

    function crc32(str) {
        var table = new Array(256);
        for (var i = 0; i < 256; i++) {
            var c = i;
            for (var j = 0; j < 8; j++) {
                c = ((c & 1) ? (0xEDB88320 ^ (c >>> 1)) : (c >>> 1));
            }
            table[i] = c;
        }
    
        var crc = 0 ^ (-1);
    
        for (var i = 0; i < str.length; i++) {
            crc = (crc >>> 8) ^ table[(crc ^ str.charCodeAt(i)) & 0xFF];
        }
    
        return (crc ^ (-1)) >>> 0;
    }
    
    function tagToColor(tag) {
        if (!tag.match(/^[A-Z]+$/)) return \'000000\';
    
        let hash = crc32(tag);
        let color = (hash & 0xffffff).toString(16); // Convert to hex
    
        while (color.length < 6) color = \'0\' + color;
    
        return color;
    }
    
    function fetchLatestLogs() {
        const loadingArrow = document.querySelector(\'.loading-arrow\');

        loadingArrow.style.display = \'block\';
        setTimeout(() => loadingArrow.style.opacity = \'1\', 10);

        let lastEntry = document.querySelector(".log-entry:last-of-type").textContent.match(/\[\d{2}:\d{2}:\d{2}\]/);

        let lastTimestamp = null;
        
        if(!lastEntry) {
            console.log("Didnt find timestamp");
            setTimeout(fetchLatestLogs, 5000);
            return;
        } else
            lastTimestamp = lastEntry[0];

        lastTimestamp = lastTimestamp.replace(/\[|\]/g, \'\');

        const queryURL = `/serverlog?since=${lastTimestamp}&format=raw`;

        fetch(queryURL)
        .then(response => response.text())
        .then(data => {
            if (data) {
                const lines = data.split("\n");

                lines.forEach(line => {
                    const match = line.match(/\[\d{2}:\d{2}:\d{2}\]\s+\[(.*?)\]/);
                    const tag = match ? match[1] : \'\';
                
                    if (tag === \'chat\') return;
                    
                    if(match) handleLogUpdate(line);
                    
                    const color = tagToColor(tag);
                
                    const logEntry = document.createElement(\'div\');
                    logEntry.className = \'log-entry\';

                    if(tag === "debug") logEntry.classList.add(\'debug-entry\');
                
                    const tagDiv = document.createElement(\'div\');
                    tagDiv.className = \'tag\';
                    tagDiv.style.backgroundColor = `#${color}`;
                    logEntry.appendChild(tagDiv);
                
                    logEntry.appendChild(document.createTextNode(line));
                
                    // Add the copy icon to the log entry
                    const copyIcon = document.createElement(\'span\');
                    copyIcon.className = \'copy-icon\';
                    copyIcon.textContent = \'📋\';
                    logEntry.appendChild(copyIcon);
                
                    document.body.appendChild(logEntry);

                    setTimeout(() => {
                        logEntry.classList.add(\'visible\');
                    }, 10);
                });
                
                
                window.scrollTo(0, document.body.scrollHeight);
            }
        })
        .finally(() => {
            loadingArrow.style.opacity = \'0\';
            setTimeout(() => loadingArrow.style.display = \'none\', 500);

            setTimeout(fetchLatestLogs, 5000); // Poll every 5 seconds
        });
    }

    window.onload = function() { 
        setTimeout(fetchLatestLogs, 5000); // Start polling 5 seconds after initial load

        setTimeout(function() {
            window.scrollTo(0, document.body.scrollHeight);
        }, 100); 
    }</script>';

    $response->getBody()->write($styledOutput);
    return $response->withHeader('Content-Type', 'text/html');
});
