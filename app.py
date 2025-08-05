# 导入 render_template, make_response
from flask import Flask, request, jsonify, abort, render_template, url_for, make_response
from flask_mysqldb import MySQL
import json
from datetime import datetime

app = Flask(__name__)

# --- Database Configuration ---
app.config['MYSQL_HOST'] = 'localhost'
app.config['MYSQL_PORT'] = 3306
app.config['MYSQL_USER'] = 'root'
app.config['MYSQL_PASSWORD'] = 'root'
app.config['MYSQL_DB'] = 'security_demo_db'
app.config['MYSQL_CURSORCLASS'] = 'DictCursor' # 建议添加，这样查询结果就是字典格式，更方便

mysql = MySQL(app)


# --- CORS Preflight (OPTIONS) Request Handler ---
# 这个装饰器在请求到达路由函数之前执行
@app.before_request
def handle_options_request():
    if request.method == 'OPTIONS':
        response = make_response()
        # 对于OPTIONS请求，只需要设置允许的方法和头部
        # Access-Control-Allow-Origin 将在 after_request 中统一添加
        response.headers.add('Access-Control-Allow-Headers', 'Content-Type,Authorization')
        response.headers.add('Access-Control-Allow-Methods', 'POST, GET, OPTIONS')
        return response # 返回响应，这样实际的路由函数就不会被执行了


# --- Add CORS Headers to All Responses ---
# 这个装饰器在请求处理完成后，响应返回给客户端之前执行
@app.after_request
def add_cors_headers(response):
    # 统一为所有响应添加 Access-Control-Allow-Origin 头部
    # 包括 OPTIONS 预检请求的响应，以及实际的 POST 请求的响应
    response.headers.add('Access-Control-Allow-Origin', '*')
    return response


# --- API Endpoint for Logging ---
@app.route('/api/log', methods=['POST']) # 只处理 POST 请求
def log_entry():
    try:
        data = request.get_json()

        if not data or 'logs' not in data or not isinstance(data['logs'], list):
            abort(400, description="Invalid input data. Expected a JSON object with a 'logs' array.")

        logs_to_insert = []
        for log_item in data['logs']:
            # 这里是关键的检查！
            if not all(key in log_item for key in ['log_type', 'message', 'timestamp']):
                app.logger.warning(f"Skipping malformed log item: {log_item}")
                continue

            log_type = log_item['log_type']
            message = log_item['message']
            extra_data = json.dumps(log_item.get('data')) if log_item.get('data') else None
            timestamp_str = log_item['timestamp']

            try:
                timestamp_obj = datetime.fromisoformat(timestamp_str.replace('Z', '+00:00'))
                timestamp_sql = timestamp_obj.strftime('%Y-%m-%d %H:%M:%S')
            except ValueError:
                app.logger.warning(f"Could not parse timestamp: {timestamp_str}. Using it as is.")
                timestamp_sql = timestamp_str

            logs_to_insert.append((log_type, message, extra_data, timestamp_sql))

        if not logs_to_insert:
            return jsonify({'status': 'success', 'message': 'No valid logs to insert.'}), 200

        cursor = mysql.connection.cursor()
        sql = "INSERT INTO logs (log_type, message, data, timestamp) VALUES (%s, %s, %s, %s)"

        cursor.executemany(sql, logs_to_insert)
        mysql.connection.commit()
        cursor.close()

        return jsonify({'status': 'success', 'message': f'Successfully logged {len(logs_to_insert)} entries.'}), 200

    except Exception as e:
        app.logger.error(f"Error processing log request: {e}")
        if 'cursor' in locals() and cursor:
            mysql.connection.rollback()
            cursor.close()
        abort(500, description="An internal server error occurred while processing your request.")


# --- Route for the Admin Log Viewer ---
@app.route('/admin/logs')
def view_logs_admin():
    try:
        cursor = mysql.connection.cursor()
        cursor.execute("SELECT id, log_type, message, data, timestamp, received_at FROM logs ORDER BY id DESC LIMIT 50")
        logs_raw = cursor.fetchall()
        cursor.close()

        logs = []
        for log_row in logs_raw:
            if log_row['data']:
                try:
                    log_row['data'] = json.loads(log_row['data'])
                except json.JSONDecodeError:
                    log_row['data'] = "Error decoding JSON"
            logs.append(log_row)

        return render_template('logs.html', logs=logs)

    except Exception as e:
        app.logger.error(f"Error retrieving logs for admin view: {e}")
        return "<h1>Error</h1><p>Failed to load logs.</p>", 500


if __name__ == '__main__':
    app.run(debug=True, host='0.0.0.0', port=5000)