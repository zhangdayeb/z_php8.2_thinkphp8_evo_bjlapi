

    //获取发送的数据 荷官开牌
    public function set_post_data(): string
    {

        LogHelper::debug('===================================================');
        LogHelper::debug('=== 开牌流程开始 接收到荷官开牌请求===');
        LogHelper::debug('===================================================');
        LogHelper::debug('荷官原始参数', $params);
        
        //过滤数据
        $postField = 'gameType,tableId,xueNumber,puNumber,result,ext,pai_result';
        $params = $this->request->only(explode(',', $postField), 'param', null);
        //数据验证
        try {
            validate(validates::class)->scene('lz_post')->check($params);
        } catch (ValidateException $e) {
            return show([], config('ToConfig.http_code.error'), $e->getError());
        }
        //查询是否重复上传
        $map = array();
        $map['status'] = 1;
        $map['table_id'] = $params['tableId'];
        $map['xue_number'] = $params['xueNumber'];
        $map['pu_number'] = $params['puNumber'];
        $map['game_type'] = $params['gameType'];

        //查询当日最新的一铺牌
        $info = Luzhu::whereTime('create_time', 'today')->where('result','<>',0)->where($map)->find();
        if (!empty($info)) show($info, 0, '数据重复上传');


        // 根据游戏类型调用相应服务
        switch ($map['game_type']) {
            case 3:
                LogHelper::debug('调用百家乐开牌服务', ['table_id' => $map['table_id']]);
                $card = new CardSettlementService();
                return $card->open_game($map);
            default:
                LogHelper::error('不支持的游戏类型', ['game_type' => $map['game_type']]);
                show([], 404, 'game_type错误！');
        }
    }
    

    


    //设置靴号 荷官主动换靴号
    public function set_xue_number(): string
    {
        //过滤数据
        $postField = 'tableId,num_xue,gameType';
        $post = $this->request->only(explode(',', $postField), 'param', null);

        try {
            validate(validates::class)->scene('lz_set_xue')->check($post);
        } catch (ValidateException $e) {
            return show([], config('ToConfig.http_code.error'), $e->getError());
        }

        //取才创建时间最后一条数据
        $find = Luzhu::where('table_id', $post['tableId'])->order('id desc')->find();

        if ($find) {
            $xue_number['xue_number'] = $find->xue_number + 1;
        } else {
            $xue_number['xue_number'] = 1;
        }
        $post['status'] = 1;
        $post['table_id'] = $post['tableId'];
        $post['xue_number'] = $xue_number['xue_number'];
        $post['pu_number'] = 1;
        $post['update_time'] = $post['create_time'] = time();
        $post['game_type'] = $post['gameType'];
        $post['result'] = 0;
        $post['result_pai'] = 0;

        $save = (new Luzhu())->save($post);
        if ($save) show($post);
        show($post, config('ToConfig.http_code.error'));
    }

    //开局信号
    public function set_start_signal(): string
    {
        $table_id = $this->request->param('tableId', 0);               
        $time = (int) $this->request->param('time', 45);
        if ($table_id <= 0) show([], config('ToConfig.http_code.error'), 'tableId参数错误');
        $data = [
            'start_time' => time(),
            'countdown_time' => $time,
            'run_status' => 1,
            'wash_status'=>0,
            'update_time' => time(),
        ];
        $save = Table::where('id', $table_id)->update($data);
        if (!$save) {
            show($data, config('ToConfig.http_code.error'));
        }
        $data['table_id'] = $table_id;
        Queue::push(TableStartJob::class, $data,'bjl_start_queue');
        show($data);
    }

    //结束信号
    public function set_end_signal(): string
    {
        $table_id = $this->request->param('tableId', 0);
        if ($table_id <= 0) show([], config('ToConfig.http_code.error'));
        $save = Table::where('id', $table_id)
            ->update([
                'run_status' => 2,
                'wash_status'=>0,
                'update_time' => time(),
            ]);
        if ($save) show([]);
        show([], config('ToConfig.http_code.error'));
    }

