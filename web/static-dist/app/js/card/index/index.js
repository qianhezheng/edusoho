webpackJsonp(["app/js/card/index/index"],[function(t,e,a){"use strict";function n(t){return t&&t.__esModule?t:{default:t}}var r=a("b334fd7e4c5a19234db2"),o=n(r);$("a[role=filter-change]").click(function(t){window.location.href=$(this).data("url")}),$(".receive-modal").click(),$("body").on("click",".money-card-use",function(){$("body").off("click",".money-card-use");var t=$(this).data("url"),e=$(this).data("target-url"),a=$(this).prev().text();$.post(t,function(t){(0,o.default)("success",Translator.trans("学习卡已使用，充值"+a+"虚拟币成功，可前往【账户中心】-【我的账户】查看充值情况。"),2),setTimeout("window.location.href = '"+e+"'",2e3)}).error(function(){(0,o.default)("danger",Translator.trans("失败！"),1)})})}]);