<?php

namespace App\Console\Commands;

use App\Services\ServerService;
use App\Services\TelegramService;
use App\Utils\CacheKey;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class CheckServer extends Command
{
    // 掉线计数缓存键前缀
    const OFFLINE_COUNT_KEY_PREFIX = 'SERVER_OFFLINE_COUNT_';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check:server {--report : 发送节点状态报告}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '节点检查任务';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        if ($this->option('report')) {
            $this->sendServerReport();
        } else {
            $this->checkOffline();
        }
    }

    private function checkOffline()
    {
        $serverService = new ServerService();
        $servers = $serverService->getAllServers();
        foreach ($servers as $server) {
            if ($server['parent_id'])
                continue;
            if (!$server['show'])
                continue; // 过滤掉 show=0 的节点

            // 检查节点是否掉线 (超过300秒未更新)
            $isOffline = $server['last_check_at'] && (time() - $server['last_check_at']) > 300;

            // 生成掉线计数缓存键
            $offlineCountKey = self::OFFLINE_COUNT_KEY_PREFIX . $server['type'] . '_' . $server['id'];

            if ($isOffline) {
                // 节点掉线，增加掉线计数
                $offlineCount = Cache::get($offlineCountKey, 0) + 1;
                Cache::put($offlineCountKey, $offlineCount, 3600); // 缓存1小时

                // 如果连续掉线5次，发送通知
                if ($offlineCount >= 5) {
                    // 检查是否已经发送过通知，避免重复发送
                    $notifiedKey = $offlineCountKey . '_NOTIFIED';
                    if (!Cache::has($notifiedKey)) {
                        $telegramService = new TelegramService();
                        $message = sprintf(
                            "🔴 节点连续掉线通知\n----\n📍 节点名称：%s\n🆔 节点ID：%d\n⏰ 掉线次数：%d次\n",
                            $server['name'],
                            $server['id'],
                            $offlineCount
                        );
                        $telegramService->sendMessageWithAdmin($message);

                        // 标记已通知，24小时内不再重复通知
                        Cache::put($notifiedKey, true, 86400);
                    }
                }
            } else {
                // 节点在线，重置掉线计数
                Cache::forget($offlineCountKey);
                Cache::forget(self::OFFLINE_COUNT_KEY_PREFIX . $server['type'] . '_' . $server['id'] . '_NOTIFIED');
            }
        }
    }

    private function sendServerReport()
    {
        // 检查是否启用节点状态报告
        $reportEnabled = (int) config('v2board.server_status_report_hour', 1);
        if ($reportEnabled === 0) {
            // 如果设置为0，则不发送报告
            return;
        }

        // 使用整除小时的方式判断是否应该发送报告
        // 这样可以确保在固定的整点时间发送，避免时间漂移
        $currentHour = (int) date('G'); // 当前小时 (0-23)

        // 使用缓存键记录上次发送报告的小时
        $lastReportKey = CacheKey::get('SERVER_STATUS_LAST_REPORT_TIME', null);
        $lastReportHour = Cache::get($lastReportKey, -1);

        // 只在整除的小时发送（例如间隔4小时，则在0、4、8、12、16、20点发送）
        if ($currentHour % $reportEnabled !== 0) {
            // 当前小时不是应该发送的时间点，跳过
            return;
        }

        // 检查当前小时是否已经发送过
        if ($lastReportHour === $currentHour) {
            // 这个小时已经发送过了，跳过
            return;
        }

        $serverService = new ServerService();
        $servers = $serverService->getAllServers();

        // 统计节点状态
        $totalServers = 0;
        $onlineServers = 0;
        $offlineServers = 0;
        $offlineList = [];

        foreach ($servers as $server) {
            if ($server['parent_id'])
                continue;
            if (!$server['show'])
                continue; // 过滤掉 show=0 的节点

            $totalServers++;
            // 检查节点是否在线 (超过300秒未更新则认为掉线)
            $isOnline = $server['last_check_at'] && (time() - $server['last_check_at']) <= 300;

            if ($isOnline) {
                $onlineServers++;
            } else {
                $offlineServers++;
                $offlineList[] = [
                    'name' => $server['name'],
                    'id' => $server['id']
                ];
            }
        }

        // 构造报告消息
        $message = sprintf(
            "📊 节点状态报告\n----------------\n📈 总节点数：%d\n✅ 在线节点：%d\n❌ 离线节点：%d\n",
            $totalServers,
            $onlineServers,
            $offlineServers
        );

        // 如果有离线节点，列出离线节点信息（只显示节点名和ID）
        if (!empty($offlineList)) {
            $message .= "\n📋 离线节点列表：\n";
            foreach ($offlineList as $index => $server) {
                $message .= sprintf("%d. %s - id:%d\n", $index + 1, $server['name'], $server['id']);
            }
        }

        // 发送报告给管理员
        $telegramService = new TelegramService();
        $telegramService->sendMessageWithAdmin($message);

        // 更新最后发送报告的小时
        Cache::put($lastReportKey, $currentHour, 3600); // 缓存1小时
    }
}
