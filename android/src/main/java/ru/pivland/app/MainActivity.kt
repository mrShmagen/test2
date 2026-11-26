package ru.pivland.app

import android.app.Activity
import android.app.AlertDialog
import android.os.Bundle
import android.text.InputType
import android.widget.*
import org.json.JSONArray
import org.json.JSONObject
import java.io.IOException
import java.net.CookieManager
import java.net.HttpURLConnection
import java.net.URL
import java.util.concurrent.Executors

class MainActivity : Activity() {
    private val worker = Executors.newSingleThreadExecutor()
    private lateinit var page: LinearLayout
    private val api = "https://app.pivland.ru/new4/index.php?api="
    private var generation = 0
    private var screen = ""
    private var products = JSONArray()
    companion object {
        private val cookies = CookieManager()
    }

    override fun onCreate(state: Bundle?) {
        super.onCreate(state)
        val root = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL }
        val nav = LinearLayout(this)
        root.addView(nav)
        for ((name, action) in listOf("Каталог" to { catalog() }, "Кабинет" to { account() })) {
            nav.addView(button(name, action), LinearLayout.LayoutParams(0, -2, 1f))
        }
        val scroll = ScrollView(this)
        page = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            val inset = (16 * resources.displayMetrics.density).toInt()
            setPadding(inset, inset, inset, inset)
        }
        scroll.addView(page)
        root.addView(scroll)
        setContentView(root)
        if (getPreferences(0).getBoolean("adult", false)) catalog()
        else AlertDialog.Builder(this).setTitle("Вам уже исполнилось 18 лет?")
            .setCancelable(false).setPositiveButton("Да") { _, _ ->
                getPreferences(0).edit().putBoolean("adult", true).apply()
                catalog()
            }.setNegativeButton("Нет") { _, _ -> finish() }.show()
    }

    private fun button(label: String, action: () -> Unit) = Button(this).apply {
        text = label
        isAllCaps = false
        setOnClickListener { action() }
    }
    private fun label(value: String, size: Float = 16f) {
        page.addView(TextView(this).apply { textSize = size; text = value })
    }
    private fun start(name: String, title: String) {
        generation++
        screen = name
        page.removeAllViews()
        (page.parent as? ScrollView)?.scrollTo(0, 0)
        label(title, 25f)
    }

    private fun request(route: String, body: JSONObject? = null, success: (JSONObject) -> Unit) {
        val id = generation
        val status = TextView(this).apply { text = "Загрузка…" }
        page.addView(status)
        worker.execute {
            val result = runCatching {
                val url = URL(api + route)
                val conn = url.openConnection() as HttpURLConnection
                try {
                    conn.instanceFollowRedirects = false
                    conn.connectTimeout = 10000
                    conn.readTimeout = 15000
                    cookies.get(url.toURI(), emptyMap()).forEach { (k, v) ->
                        if (v.isNotEmpty()) conn.setRequestProperty(k, v.joinToString("; "))
                    }
                    if (body != null) {
                        conn.requestMethod = "POST"
                        conn.doOutput = true
                        conn.setRequestProperty("Content-Type", "application/json; charset=utf-8")
                        conn.outputStream.use { it.write(body.toString().toByteArray()) }
                    }
                    val code = conn.responseCode
                    cookies.put(url.toURI(), conn.headerFields)
                    val stream = if (code in 200..299) conn.inputStream else conn.errorStream
                    val json = stream?.bufferedReader()?.use { JSONObject(it.readText()) }
                    if (json == null || !json.optBoolean("success") || code !in 200..299)
                        throw IOException(json?.optString("message") ?: "Ошибка сервера ($code)")
                    json
                } finally { conn.disconnect() }
            }
            runOnUiThread {
                if (generation != id || isDestroyed || isFinishing) return@runOnUiThread
                result.onSuccess { page.removeView(status); success(it) }.onFailure {
                    status.text = if (it is IOException && !it.message.isNullOrBlank()) it.message else "Не удалось получить данные"
                    val retry = Button(this@MainActivity).apply { text = "Повторить" }
                    retry.setOnClickListener { page.removeView(retry); page.removeView(status); request(route, body, success) }
                    page.addView(retry)
                }
            }
        }
    }
    private fun catalog() {
        start("catalog", "Каталог")
        request("products") { products = it.getJSONArray("data"); showProducts() }
    }
    private fun showProducts() {
        start("catalog", "Каталог")
        if (products.length() == 0) label("Каталог пока пуст")
        for (i in 0 until products.length()) {
            val item = products.getJSONObject(i)
            page.addView(button(item.optString("name")) { detail(item) })
        }
    }
    private fun detail(item: JSONObject) {
        start("detail", item.optString("name"))
        label(item.optString("category_name"))
        if (!item.isNull("price")) label(item.optString("price") + " ₽")
        if (!item.isNull("description")) label(item.optString("description"))
        label("Наличие по магазинам", 20f)
        request("product_stock&product_id=" + item.getInt("id")) {
            val shops = it.getJSONArray("data")
            var available = 0
            for (i in 0 until shops.length()) {
                val shop = shops.getJSONObject(i)
                val qty = shop.optDouble("quantity", 0.0)
                if (qty > 0) available++
                label(shop.optString("name") + " · " + if (qty > 0) shop.optString("quantity") else "Нет в наличии")
            }
            label("В наличии в магазинах: $available")
        }
    }
    private fun account() {
        start("account", "Личный кабинет")
        request("current_user") {
            if (it.optBoolean("logged_in")) {
                label(it.getJSONObject("user").optString("login"))
                page.addView(button("Выйти") { request("logout", JSONObject()) { account() } })
            } else {
                val login = EditText(this).apply { hint = "Логин"; isSingleLine = true }
                val password = EditText(this).apply {
                    hint = "Пароль"
                    inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_PASSWORD
                }
                page.addView(login)
                page.addView(password)
                page.addView(button("Войти") {
                    val payload = JSONObject().put("login", login.text.toString()).put("password", password.text.toString())
                    request("auth", payload) { account() }
                })
            }
        }
    }
    @Deprecated("Legacy back navigation")
    override fun onBackPressed() {
        if (screen == "detail") showProducts() else super.onBackPressed()
    }
    override fun onDestroy() { generation++; worker.shutdownNow(); super.onDestroy() }
}
