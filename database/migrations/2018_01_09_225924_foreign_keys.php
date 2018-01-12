<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class ForeignKeys extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {


        Schema::table('agents', function ($table) {
            $table->integer('company_id')->unsigned()->change();
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade')->onUpdate('cascade');
        });

        Schema::table('commands', function ($table) {
            $table->integer('agent_id')->unsigned()->change();
            $table->foreign('agent_id')->references('id')->on('agents')->onDelete('cascade')->onUpdate('cascade');
        });

        Schema::table('companies', function ($table) {
            $table->integer('user_id')->unsigned()->change();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade')->onUpdate('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('agents', function ($table) {
            $table->integer('company_id')->unsigned(false)->change();
            $table->dropForeign('agents_company_id_foreign');
        });

        Schema::table('commands', function ($table) {
            $table->integer('agent_id')->unsigned(false)->change();
            $table->dropForeign('commands_agent_id_foreign');
        });

        Schema::table('companies', function ($table) {
            $table->integer('user_id')->unsigned(false)->change();
            $table->dropForeign('companies_user_id_foreign');
        });
    }
}
