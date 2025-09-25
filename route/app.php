<?php
use think\facade\Route;

/**
 * 百家乐游戏路由配置
 */

// ========== 基础路由 ==========
// 首页
Route::rule('/$', '/index/index');

// ========== 台桌信息 ==========
// 台桌列表
Route::rule('bjl/get_table/list$', '/game.GetForeignTableInfo/get_table_list');

// 台桌详情（靴号、铺号等）
Route::rule('bjl/get_table/table_info$', '/game.GetForeignTableInfo/get_table_info');

// 台桌统计（庄闲和次数）
Route::rule('bjl/get_table/get_table_count$', '/game.GetForeignTableInfo/get_table_count');

// ========== 露珠数据 ==========
// 露珠列表
Route::rule('bjl/get_table/get_data$', '/game.GetForeignTableInfo/get_lz_list');


// ========== 游戏控制 ==========
// 开局信号
Route::rule('bjl/start/signal$', '/game.GetForeignTableInfo/set_start_signal');

// 结束信号
Route::rule('bjl/end/signal$', '/game.GetForeignTableInfo/set_end_signal');

// 洗牌状态
Route::rule('bjl/get_table/wash_brand$', '/game.GetForeignTableInfo/get_table_wash_brand');

// 设置靴号
Route::rule('bjl/get_table/add_xue$', '/game.GetForeignTableInfo/set_xue_number');

// 手动开牌
Route::rule('bjl/get_table/post_data$', '/game.GetForeignTableInfo/set_post_data');

// ========== 用户相关 ==========
// 用户信息
Route::rule('bjl/user/info$', '/game.GetForeignTableInfo/get_user_info');

// ========== 投注相关 ==========
// 用户下注
Route::rule('bjl/bet/order$', '/order.Order/user_bet_order');

// 投注历史
Route::rule('bjl/bet/history$', '/game.GameInfo/get_user_bet_history');

