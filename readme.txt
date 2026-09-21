# 終極登入 (Ultimate Login)

讓顧客透過 LINE 或 Google 登入綁定帳號，並在 WooCommerce 訂單狀態變更時自動透過 LINE 推播通知；同時可推播新訂單通知到管理員/員工共用的 LINE 群組或聊天室。

## 功能

- LINE / Google / Apple OAuth 2.0 社交登入與帳號綁定
- 登入、註冊頁社交按鈕並排顯示（空間足夠時）
- 訂單狀態變更時自動推播 LINE Flex Message 給下單顧客
- 訂單建立（等待付款）時也能發送通知
- 顧客可見備注推播 LINE
- 顧客可在帳戶詳細資料頁自行開關 LINE / Email 訂單通知
- 新訂單自動推播通知到指定的 LINE 群組/聊天室（管理員/員工共用，設定在「管理員通知」分頁，與顧客通知完全獨立）
- 「模組」分頁可個別關閉不需要的功能區塊（社交登入／訂單通知／系統信件），關閉後對應頁籤與前台輸出整個不出現

## 安裝

1. WordPress 後台 → 外掛 → 安裝外掛 → 上傳外掛，選擇本 zip 檔並啟用。
2. 前往後台側邊選單「終極登入」→「授權」，輸入有效授權金鑰。
3. 依照其餘頁籤說明完成設定。

## LINE 前置作業

1. 到 https://developers.line.biz/console/ 建立 Provider。
2. 同一 Provider 下建立 Messaging API channel，發行長效 Channel Access Token。
3. 同一 Provider 下建立 LINE Login channel，取得 Channel ID / Secret。
4. LINE Login channel 的 Callback URL 填入：`https://你的網域/?wclon_action=callback`
5. 完成 Linked OA 設定，綁定官方帳號。

## Google 前置作業

1. 到 Google Cloud Console 建立 OAuth 2.0 用戶端憑證。
2. 授權重新導向 URI 填入：`https://你的網域/?wclon_action=google_callback`
3. 將 Client ID / Client Secret 填入外掛設定頁 Google tab。

## 管理員 LINE 群組通知（「管理員通知」分頁）

把官方帳號跟需要收到通知的員工一起加入同一個 LINE 群組（或多人聊天室），新訂單會用官方帳號的身分發到群組裡，所有成員都看得到；不需要每人各自登入綁定。

1. 到 LINE Developers Console 的 Messaging API channel 頁籤，貼上外掛設定頁提供的 Webhook URL（`?wcan_action=webhook`）並開啟「Use webhook」。
2. 在同一頁籤把「Allow bot to join group chats」開啟。
3. 建立一個 LINE 群組（或多人聊天室），把官方帳號與需要收到通知的員工都加入。
4. 官方帳號被加入群組後（或群組內任何人發言一次），外掛會自動擷取該群組/聊天室 ID 並記錄在設定頁的名單裡。

## 運作方式

- 顧客在登入、註冊、結帳或帳戶頁點擊 LINE / Google / Apple 按鈕，授權後系統記錄其帳號 ID。
- 訂單狀態變更（或訂單建立）時自動推播 Flex Message 給綁定的顧客，同時推播給已設定的管理員 LINE 群組。
- 推播結果記錄在訂單備注，同一狀態/同一新訂單不會重複發送。

## 注意事項

- 顧客與管理員都必須是 LINE 官方帳號好友（或在同一個已加入的群組內）才能收到推播。
- 推播計入官方帳號訊息額度（免費方案每月 200 則），顧客通知與管理員群組通知共用同一個額度。
- 社交按鈕顯示位置可在「一般設定」分頁勾選：結帳頁、購物車頁（傳統短代碼版 `[woocommerce_cart]`）、會員中心。
- 購物車頁與結帳頁的綁定列分成兩個區塊：LINE（綁定後可收訂單通知，已綁定的顧客不會再看到這一區）與 Google / Apple（單純快速登入）；兩區標題與 LINE 區的按鈕樣式都可在「一般設定」分頁自訂。
- 短代碼 `[wclon_line_connect]` / `[wclon_google_connect]` / `[wclon_apple_connect]` 可在任何頁面顯示個別完整版綁定按鈕；`[wclon_social_bar]` 可顯示跟結帳頁一樣的綁定列（例如放在區塊版購物車頁）。

## 變更紀錄

### 1.37.8
- 從後台選單進入設定頁時不再跳到上次停留的頁籤（儲存後仍會停留在原頁籤）。

### 1.37.7
- 授權 API 改用 CtrlA 新網址。
- 新序號輸入提示改為 CTRLA-，並正確顯示新舊序號前綴。

### 1.37.6
- 調整 Flex Message 預覽卡片與訊息類型選單的間距。

### 1.37.5
- 後台設定改為兩層功能頁籤，並精簡說明文字、統一手機版導覽與間距。
- 優化授權狀態頁，增加綁定網站、驗證時間、期限及網站上限等資訊。
- Turnstile 新增驗證服務無法連線時「暫時放行／阻擋請求」選項。

### 1.37.4
- 新增 Cloudflare Workers + D1 授權啟用、每日驗證與解除綁定；驗證成功快取 24 小時，服務中斷時提供 14 天離線寬限。

### 1.37.3
- 完成 WooCommerce 11.1 相容性稽核，補上版本 metadata；明確標示目前僅支援傳統購物車與結帳流程，不宣告相容 Cart／Checkout Blocks。

### 1.37.2
- 後台設定頁的標題、透明頁籤、字級與間距對齊 WooCommerce；手機版頁籤可橫向捲動，並限制設定區最大寬度。

### 1.37.1
- 外掛更新頁的品牌圖示改用 Lucide 圖示庫的「登入箭頭」造型，取代原本的舊圖案；後台側邊選單「快捷鍵」的圖示不受影響。

### 1.37.0
- 後台側邊選單與「終極電商」（Ultimate E-commerce）合併成一個共用的「快捷鍵」主選單，底下分別是「終極電商」與「終極登入」的功能項目；只裝其中一個外掛時，「快捷鍵」選單一樣正常顯示。

### 1.36.1
- 修正：關閉「模組」分頁的「訂單通知」模組後，「LINE」分頁「綁定歡迎優惠券」卡片的推播標題色會變成無法設定，本版修正。
