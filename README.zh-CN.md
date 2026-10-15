<div align="center">

[English](README.md) | **简体中文**

<img src="public/images/logo.png" alt="Wai Hong Brothers" width="320">

# ClaimFlow

公司内部用的报销（claim）系统。员工拍收据上传，系统帮忙把大部分资料填好，再由 Admin 审批。

</div>

![Dashboard](docs/screenshots/02-dashboard.png)

## 简介

不用再收一叠纸收据、一张张打进 Excel。每个员工自己上传收据，照片和 claim 存在一起，不怕弄丢。月底会计直接导出 Excel 就可以了。

读收据用的是装在自己电脑或 server 上的 Tesseract OCR，没有用 AI，也不连云端 API。所以不用钱，收据也不会传到外面。

技术：Laravel 13、PHP 8.3、MariaDB/MySQL、Blade、Tailwind CSS 4。

## 有哪些功能

- 手机可以直接拍照，也可以上传 JPG / PNG / PDF。一张 claim 可以放几张收据。
- OCR 会填好商家、地址、日期、时间、每一项明细、SST / 服务费和总额。自动填的格子会标黄色，提醒你核对一下。
- 类别会自动选。这家商家以前选过什么就用什么，没有的话按关键词判断（KFC → Food & Beverage）。关键词可以在 Settings 里改。
- 审批流程：Draft → Submitted → Approved 或 Rejected。被退回的单会附上 Admin 写的原因，员工改好可以再送审。
- 金额由 server 自己重新算。算出来跟收据上的总额对不上，会显示差多少。
- Dashboard 可以跟上个月比较，有每月走势图，也可以按类别、按项目看。
- Reports 可以筛选，再导出 CSV（Excel 能直接打开）。每行有编号，最后一行是 Grand Total。
- Audit Log 记录谁新建、修改、审批、导出了什么。
- 手机可以用，有底部菜单，每页都适合手机屏幕。

## 员工怎么用

**1. 登录**：用公司 email 和密码。

![登录](docs/screenshots/01-login.png)

**2. 新建 claim**：点左边菜单的 **New Claim**。
- 手机按 **Take Photo**；电脑按 **Choose File**，或把收据拖进框里。
- 可以一次放几张收据。没有收据就按 **Enter manually**。
- 按 **Continue**，每张收据等几秒。

拍的时候收据摊平、光线够、收据占满画面，读得最准。

![新建 claim](docs/screenshots/04-new-claim.png)

**3. 核对资料**：左边是收据，右边是表格。
- 黄色的格子对着照片检查一下。明细、数量、价钱都可以直接改，也可以加一行。
- 还没弄好就按 **Save Draft**，下次再继续。确定了就按 **Submit for approval**。

送审之后单子会锁住，要等 Admin 审完才能再动。

![核对 OCR 资料](docs/screenshots/05-check-ocr.png)

**4. 跟进**：在 **My Claims** 看自己的所有单。上面有 All / Draft / Pending / Approved / Rejected 几个分页，也可以搜索和筛选。

![我的 claim](docs/screenshots/03-claims.png)

点进去可以看到收据、明细，还有审批走到哪一步。

![Claim 详情](docs/screenshots/06-claim-details.png)

**被退回了？** Dashboard 和铃铛会有红色提示，写着 Admin 的原因。打开那张单，改好后按 **Resubmit for approval**。

## Admin 怎么审批

进 **Approvals**，红色数字是等待审批的数量。Pending 分页最旧的排在最前面。

![审批列表](docs/screenshots/07-approvals.png)

打开单子，对照收据和金额：
- 没问题按 **Approve**，备注可写可不写。
- 有问题按 **Reject**，一定要写原因，员工会看到。

审完会自动跳到下一张待审的单。

自己的单不能自己批，Admin 也一样。批准后的单不能再改。

![审核](docs/screenshots/08-review.png)

**Reports** 可以筛选和导出 CSV，导出的只会是你有权限看的资料。

![报表](docs/screenshots/09-reports.png)

手机画面：

<p>
<img src="docs/screenshots/11-mobile-dashboard.png" width="260" alt="手机 Dashboard">
&nbsp;&nbsp;
<img src="docs/screenshots/12-mobile-claims.png" width="260" alt="手机 claim 列表">
</p>

## 谁能看到什么

规则很简单：每个人只看得到自己的 claim，只有 Admin 看得到全部人的。

| 角色 | 看得到 | Reports | 审批 | Settings |
|---|---|---|---|---|
| Staff | 自己的 | - | - | - |
| Manager | 自己的 | - | - | - |
| Owner | 自己的 | - | - | - |
| Admin | 全部 | 可以 | 可以 | 可以 |

程序看的是「权限」，不是角色名字。以后如果想让 Owner 也看全部，Admin 在 **Settings → Roles & Permissions** 帮 Owner 勾上 `claims.view_all` 就行，不用改代码。部门主管可以用 `claims.view_department`，只看自己部门的。

权限是在 server 检查的。就算在网址里改 claim 编号，也只会得到 403。

![角色与权限](docs/screenshots/10-roles.png)

### 金额怎么算

- 每行金额 = 数量 × 单价 − 折扣 + 税。收据上只有总数的话，单价留空，直接填 Amount。
- Claim 总额 = 所有行加起来。用「分」来算，不会有小数误差。
- 有三个数字分开存：OCR 读到的（只当参考）、员工确认的收据总额、系统算出来的总额。对不上就显示差额，不会互相覆盖。
- 标了 *receipt required* 的类别，没收据不能送审，但可以先存 draft。

## 安装（Windows + XAMPP）

需要：PHP 8.3 以上（要有 pdo_mysql、mbstring、fileinfo、gd、openssl、zip，跑测试要 pdo_sqlite）、Composer、Node.js 20 以上、MySQL 或 MariaDB。

```powershell
cd C:\xampp\htdocs
git clone https://github.com/Roderick1218/Claim-System.git claim-system
cd claim-system
composer install
copy .env.example .env
php artisan key:generate
```

改 `.env`：数据库资料、`APP_URL`、`TESSERACT_PATH`，要读 PDF 的话再加 Poppler 的路径。然后建数据库：

```sql
CREATE DATABASE claim_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

```powershell
php artisan migrate
php artisan db:seed --class=RolePermissionSeeder
php artisan db:seed --class=ClaimCategorySeeder
php artisan db:seed --class=DemoDataSeeder   # 测试账号，只在本机测试用
npm install
npm run build
```

打开 `http://localhost/claim-system/public`。或者跑 `php artisan serve`，然后打开 `http://127.0.0.1:8000`。

### 测试账号

密码都是 `password123`。正式上线前要删掉。

| Email | 角色 | 部门 |
|---|---|---|
| admin@claim.local | admin | Accounts |
| owner@claim.local | owner | Management |
| manager@claim.local | manager | Sales |
| staff@claim.local | staff | Sales |
| staff2@claim.local | staff | Operations |

正式 server 不要跑 demo seeder，第一个 Admin 用 `php artisan tinker` 建：

```php
$u = App\Models\User::create(['name' => 'Admin', 'email' => 'admin@company.com', 'password' => 'CHANGE-ME-123']);
$u->roles()->attach(App\Models\Role::where('name', 'admin')->first());
```

换 logo：把新图存成 `public/images/logo.png`，透明背景的 PNG 最好看。系统名字在 `.env` 的 `APP_NAME` 改。

## OCR 设置

Tesseract 一定要装：

```powershell
winget install --id UB-Mannheim.TesseractOCR -e
tesseract --version
```

在 `.env` 填完整路径，要用单引号，因为 Windows 路径用双引号会出错：

```
TESSERACT_PATH='C:\Program Files\Tesseract-OCR\tesseract.exe'
TESSERACT_LANG=eng
TESSERACT_PSM=4,6
```

`4,6` 的意思是每张收据用两种排版模式各读一次，取比较好的结果。4 适合一般收银小票，6 适合有表格的 A4 发票。

要读马来文或中文收据，可以去 https://github.com/tesseract-ocr/tessdata 下载 `msa.traineddata` 或 `chi_sim.traineddata`，放进 Tesseract 的 `tessdata` 文件夹，再设定例如 `TESSERACT_LANG=eng+msa`。最好先拿真收据试，因为多加语言有时英文反而读得差。

Apache 如果是用 Windows service 跑的，Tesseract 要装在 `C:\Program Files`。装在个人用户的文件夹，service 可能读不到。

Poppler 可以不装，只有 PDF 收据才用得到：

```powershell
winget install --id oschwartz10612.Poppler -e
```

`PDFTOTEXT_PATH` 和 `PDFTOPPM_PATH` 指向 `poppler-xx\Library\bin\` 里的 exe。没装的话 PDF 还是能上传和预览，只是不会自动读。

## 测试

```powershell
php artisan test
```

测试用内存里的 SQLite 和假的 OCR，不会动到真资料，也不需要装 Tesseract。测试范围包括：登录、权限（包括在网址改编号）、审批流程、金额计算、上传、OCR 重试、收据文字解析（用我们测过的真实收据）、报表和 CSV 导出。

## 备份

数据库和收据文件要一起备份，少了哪一样都不完整。

| 内容 | 位置 |
|---|---|
| 数据库 | `claim_system` |
| 收据文件 | `storage/app/private/receipts/` |
| 设定 | `.env`（里面有密码，不要放进 Git） |

```powershell
# 备份（port 改成你自己的 MySQL port）
$d = Get-Date -Format yyyyMMdd-HHmm
New-Item -ItemType Directory -Force D:\backup\claim\$d
cmd /c "C:\xampp\mysql\bin\mysqldump.exe --user=root --port=3306 --host=127.0.0.1 --single-transaction claim_system > D:\backup\claim\$d\claim_system.sql"
Compress-Archive C:\xampp\htdocs\claim-system\storage\app\private\receipts D:\backup\claim\$d\receipts.zip

# 还原
cmd /c "C:\xampp\mysql\bin\mysql.exe --user=root --port=3306 --host=127.0.0.1 claim_system < D:\backup\claim\<日期>\claim_system.sql"
Expand-Archive D:\backup\claim\<日期>\receipts.zip C:\xampp\htdocs\claim-system\storage\app\private\ -Force
php artisan migrate
```

可以用 Windows 的 Task Scheduler 每天跑一次，再复制一份到 NAS 或云端硬盘。

## 上线前

- `.env` 设 `APP_ENV=production`、`APP_DEBUG=false`、正式的 `APP_URL`，数据库密码要设好。
- 网站根目录要指向 `public/`，不是整个项目文件夹。
- 收据放在 `storage/app/private`，要先通过权限检查才拿得到。不要为收据跑 `storage:link`。
- `storage/` 和 `bootstrap/cache/` 要可以写入。
- 每次更新后跑 `php artisan config:cache`、`route:cache`、`view:cache`。
- 要在公司外面用的话，请用 HTTPS。
- 员工离职就停用账号，不要删除，以前的单才会保留。
- PHP 的 `upload_max_filesize` 和 `post_max_size` 要大过「收据大小上限 × 每次上传张数」。

## 目前的限制

- OCR 准不准要看照片。手写单读不到，要自己打。
- 只有一层审批（Admin），没有 email。提醒只在系统里，就是铃铛和 Dashboard。
- OCR 是上传时当场跑的，每张几秒。以后单量多了，可以改成放到背景 queue 处理。
